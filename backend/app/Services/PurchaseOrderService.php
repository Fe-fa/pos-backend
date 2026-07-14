<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PurchaseOrderService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        
    ) {
    }

    private function detailRelations(): array
    {
        return [
            'store',
            'user:user_id,first_name,last_name,email',
            'supplier:supplier_id,supplier_name,email,phone,current_balance,credit_days',
            'items.product:product_id,product_name,sku,price,cost_price',
        ];
    }

    public function allowedStoreIds(User $user): Collection
    {
        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function authorizeStoreAccess(User $user, int|string|null $storeId): void
    {
        if (!$storeId || $user->isAdmin()) {
            return;
        }

        if (!$this->allowedStoreIds($user)->contains((int) $storeId)) {
            throw new HttpResponseException(response()->json([
                'message' => 'You are not allowed to access this store.',
            ], 403));
        }
    }

    public function scopeAccessible(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('store_id', $this->allowedStoreIds($user));
    }

    public function list(User $user, array $filters = [])
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 500));

        $query = PurchaseOrder::query()
            ->with([
                'supplier:supplier_id,supplier_name,email',
                'user:user_id,first_name,last_name,email',
                'items:purchase_order_item_id,purchase_order_id,product_id,product_name_snapshot,sku_snapshot,quantity_ordered,quantity_received,quantity_rejected_total,unit_cost,tax_rate,line_total',
            ])
            ->withCount('items')
            ->withSum('items as quantity_ordered_total', 'quantity_ordered')
            ->withSum('items as quantity_received_total', 'quantity_received')
            ->orderByDesc('purchase_order_id');

        $this->scopeAccessible($query, $user)
            ->when(!empty($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(!empty($filters['supplier_id']), fn ($q) => $q->where('supplier_id', (int) $filters['supplier_id']))
            ->when(!empty($filters['status']) && $filters['status'] !== 'all', function ($q) use ($filters) {
                if ($filters['status'] === 'open') {
                    $q->whereIn('status', ['ordered', 'partially_received']);
                    return;
                }

                $q->where('status', $filters['status']);
            })
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('order_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('order_date', '<=', $filters['date_to']))
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $term = trim((string) $filters['search']);
                $q->where(function ($sub) use ($term) {
                    $sub->where('po_number', 'like', "%{$term}%")
                        ->orWhere('notes', 'like', "%{$term}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$term}%"))
                        ->orWhereHas('items', fn ($iq) => $iq->where('product_name_snapshot', 'like', "%{$term}%"));
                });
            });

        return $query->paginate($perPage);
    }

    public function show(User $user, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $this->authorizeStoreAccess($user, $purchaseOrder->store_id);
        return $purchaseOrder->load($this->detailRelations());
    }

    public function createDraft(User $user, array $data): PurchaseOrder
    {
        $this->authorizeStoreAccess($user, $data['store_id']);
        $supplier = $this->resolveSupplier($data['supplier_id']);

        return DB::transaction(function () use ($user, $data, $supplier) {
            $order = PurchaseOrder::create([
                'store_id' => (int) $data['store_id'],
                'user_id' => $user->user_id,
                'supplier_id' => $supplier->supplier_id,
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'final_total' => 0,
            ]);

            $order->update([
                'po_number' => 'PO-' . str_pad((string) $order->purchase_order_id, 5, '0', STR_PAD_LEFT),
            ]);

            $this->syncItems($order, $data['items'] ?? []);
            $order = $this->recalculateTotals($order->fresh());

            $this->auditLogService->log(
                'purchase_order.create',
                $order,
                null,
                $order->toArray(),
                ['message' => 'Purchase order draft created'],
                $order->store_id,
            );

            return $order->fresh()->load($this->detailRelations());
        });
    }

    public function update(User $user, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        $this->authorizeStoreAccess($user, $purchaseOrder->store_id);

        if (in_array($purchaseOrder->status, ['completed', 'cancelled'], true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This purchase order can no longer be edited.',
            ], 422));
        }

        if (in_array($purchaseOrder->status, ['ordered', 'partially_received'], true) && array_key_exists('items', $data)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Ordered purchase orders lock their item lines. Receive stock through GRN instead.',
            ], 422));
        }

        return DB::transaction(function () use ($user, $purchaseOrder, $data) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->purchase_order_id);
            $old = $order->toArray();

            if (array_key_exists('supplier_id', $data)) {
                $supplier = $this->resolveSupplier($data['supplier_id']);
                $order->supplier_id = $supplier->supplier_id;
            }

            foreach (['order_date', 'expected_delivery_date', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $order->{$field} = $data[$field];
                }
            }

            $order->save();

            if (array_key_exists('items', $data) && $order->status === 'draft') {
                $this->syncItems($order, $data['items']);
            }

            $order = $this->recalculateTotals($order->fresh());

            $this->auditLogService->log(
                'purchase_order.update',
                $order,
                $old,
                $order->toArray(),
                ['message' => 'Purchase order updated'],
                $order->store_id,
            );

            return $order->fresh()->load($this->detailRelations());
        });
    }

    public function place(User $user, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $this->authorizeStoreAccess($user, $purchaseOrder->store_id);

        return DB::transaction(function () use ($user, $purchaseOrder) {
            $order = PurchaseOrder::query()->with(['supplier', 'items.product'])->lockForUpdate()->findOrFail($purchaseOrder->purchase_order_id);

            if ($order->status === 'draft' && $order->items->isEmpty()) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Add at least one item before placing the purchase order.',
                ], 422));
            }

            if (!in_array($order->status, ['draft', 'ordered', 'partially_received'], true)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This purchase order cannot be placed again.',
                ], 422));
            }

            $old = $order->toArray();
            $order = $this->recalculateTotals($order);

            if ($order->status === 'draft') {
                $order->update([
                    'status' => 'ordered',
                    'dispatched_at' => now(),
                ]);
            }

            $emailSentAt = $this->dispatchSupplierEmail($order);
            if ($emailSentAt) {
                $order->update(['email_sent_at' => $emailSentAt]);
            }

            $order = $this->refreshStatus($order->fresh());

            $this->auditLogService->log(
                'purchase_order.place',
                $order,
                $old,
                $order->toArray(),
                ['message' => 'Purchase order placed and supplier notified'],
                $order->store_id,
            );

            return $order->fresh()->load($this->detailRelations());
        });
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): void
    {
        $this->authorizeStoreAccess($user, $purchaseOrder->store_id);

        if ($purchaseOrder->status !== 'draft') {
            throw new HttpResponseException(response()->json([
                'message' => 'Only draft purchase orders can be deleted.',
            ], 422));
        }

        $old = $purchaseOrder->toArray();
        $purchaseOrder->delete();

        $this->auditLogService->log(
            'purchase_order.delete',
            $purchaseOrder,
            $old,
            null,
            ['message' => 'Draft purchase order deleted'],
            $purchaseOrder->store_id,
        );
    }

    public function applyReceiptFromGrn($grn): void
    {
        if (!$grn->purchase_order_id || $grn->po_reconciled_at) {
            return;
        }

        $order = PurchaseOrder::query()
            ->with('items')
            ->lockForUpdate()
            ->find($grn->purchase_order_id);

        if (!$order) {
            return;
        }

        $items = $grn->relationLoaded('items') ? $grn->items : $grn->items()->get();
        $itemMap = $order->items->keyBy('purchase_order_item_id');

        foreach ($items as $grnItem) {
            $poItem = $itemMap->get((int) $grnItem->po_item_id);
            if (!$poItem) {
                continue;
            }

            $accepted = (int) ($grnItem->quantity_accepted ?? $grnItem->qty_received ?? 0);
            $rejected = (int) ($grnItem->quantity_rejected ?? 0);
            $newReceived = min((int) $poItem->quantity_ordered, (int) $poItem->quantity_received + max($accepted, 0));

            $poItem->update([
                'quantity_received' => $newReceived,
                'quantity_rejected_total' => (int) $poItem->quantity_rejected_total + max($rejected, 0),
            ]);
        }

        $this->refreshStatus($order->fresh('items'));
    }

    public function refreshStatus(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $order = $purchaseOrder->loadMissing('items');

        $hasAnyReceived = $order->items->contains(fn ($item) => (int) $item->quantity_received > 0);
        $allReceived = $order->items->isNotEmpty()
            && $order->items->every(fn ($item) => (int) $item->quantity_received >= (int) $item->quantity_ordered);

        $status = $order->status;
        $completedAt = $order->completed_at;

        if ($allReceived) {
            $status = 'completed';
            $completedAt = $completedAt ?: now();
        } elseif ($hasAnyReceived) {
            $status = 'partially_received';
            $completedAt = null;
        } elseif ($order->dispatched_at) {
            $status = 'ordered';
            $completedAt = null;
        }

        $order->update([
            'status' => $status,
            'completed_at' => $completedAt,
        ]);

        return $order->fresh()->load($this->detailRelations());
    }

    private function syncItems(PurchaseOrder $order, array $items): void
    {
        $existing = $order->items()->get()->keyBy('purchase_order_item_id');
        $keep = [];

        foreach (array_values($items) as $index => $itemData) {
            $itemId = (int) ($itemData['purchase_order_item_id'] ?? 0);
            $product = \App\Models\Product::query()->findOrFail((int) $itemData['product_id']);
            $quantityOrdered = (int) $itemData['quantity_ordered'];
            $unitCost = array_key_exists('unit_cost', $itemData) && $itemData['unit_cost'] !== null && $itemData['unit_cost'] !== ''
                ? (float) $itemData['unit_cost']
                : (float) ($product->cost_price ?? 0);

            $payload = [
                'product_id' => $product->product_id,
                'product_name_snapshot' => $itemData['product_name_snapshot'] ?? $product->product_name,
                'sku_snapshot' => $itemData['sku_snapshot'] ?? $product->sku,
                'quantity_ordered' => $quantityOrdered,
                'unit_cost' => round($unitCost, 2),
                'tax_rate' => round((float) ($itemData['tax_rate'] ?? 0), 2),
                'line_total' => round($quantityOrdered * $unitCost, 2),
                'notes' => $itemData['notes'] ?? null,
                'sort_order' => $index + 1,
            ];

            if ($itemId && $existing->has($itemId)) {
                $poItem = $existing->get($itemId);
                $poItem->update($payload);
                $keep[] = $poItem->purchase_order_item_id;
                continue;
            }

            $poItem = $order->items()->create([
                ...$payload,
                'quantity_received' => 0,
                'quantity_rejected_total' => 0,
            ]);
            $keep[] = $poItem->purchase_order_item_id;
        }

        if (!empty($keep)) {
            $order->items()->whereNotIn('purchase_order_item_id', $keep)->delete();
        }
    }

    private function recalculateTotals(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $order = $purchaseOrder->loadMissing('items');
        $subtotal = (float) $order->items->sum('line_total');
        $taxAmount = (float) $order->items->sum(fn ($item) => ((float) $item->line_total * (float) $item->tax_rate) / 100);
        $finalTotal = round($subtotal + $taxAmount, 2);

        $order->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'final_total' => $finalTotal,
        ]);

        return $order->fresh()->load($this->detailRelations());
    }

    private function dispatchSupplierEmail(PurchaseOrder $order): ?\Illuminate\Support\Carbon
    {
        $supplier = $order->supplier;
        if (!$supplier?->email) {
            return null;
        }

        $rows = $order->items->map(function ($item) {
            return sprintf(
                '<tr><td style="padding:8px;border:1px solid #e5e7eb;">%s</td><td style="padding:8px;border:1px solid #e5e7eb;text-align:right;">%d</td><td style="padding:8px;border:1px solid #e5e7eb;text-align:right;">%0.2f</td><td style="padding:8px;border:1px solid #e5e7eb;text-align:right;">%0.2f</td></tr>',
                e($item->product_name_snapshot),
                (int) $item->quantity_ordered,
                (float) $item->unit_cost,
                (float) $item->line_total,
            );
        })->implode('');

        $html = sprintf(
            '<div style="font-family:Arial,sans-serif;color:#0f172a"><h2>Purchase Order %s</h2><p>Hello %s,</p><p>Please find our order request below. Kindly confirm availability and delivery timing.</p><table style="border-collapse:collapse;width:100%%"><thead><tr><th style="padding:8px;border:1px solid #e5e7eb;text-align:left;">Item</th><th style="padding:8px;border:1px solid #e5e7eb;text-align:right;">Qty</th><th style="padding:8px;border:1px solid #e5e7eb;text-align:right;">Unit Cost</th><th style="padding:8px;border:1px solid #e5e7eb;text-align:right;">Line Total</th></tr></thead><tbody>%s</tbody></table><p style="margin-top:16px"><strong>PO Total Volume Cost:</strong> %0.2f</p><p>Expected delivery: %s</p><p>Thank you.</p></div>',
            e($order->po_number),
            e($supplier->supplier_name),
            $rows,
            (float) $order->subtotal,
            e(optional($order->expected_delivery_date)->format('Y-m-d') ?: 'Not specified'),
        );

        Mail::html($html, function ($message) use ($supplier, $order) {
            $message->to($supplier->email, $supplier->supplier_name)
                ->subject('Purchase Order ' . $order->po_number);
        });

        return now();
    }

    private function resolveSupplier(int|string|null $supplierId): Supplier
    {
        $supplier = Supplier::query()->find((int) $supplierId);

        if (!$supplier) {
            throw new HttpResponseException(response()->json([
                'message' => 'The selected supplier could not be found.',
            ], 422));
        }

        return $supplier;
    }
}
