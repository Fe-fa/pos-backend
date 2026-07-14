<?php

namespace App\Services;

use App\Models\Grn;
use App\Models\GrnItem;
use App\Models\GrnPayment;
use App\Models\PaymentVoucher;
use App\Models\MpesaTransaction;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GrnService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly AuditLogService $auditLogService,
        private readonly PurchaseOrderService $purchaseOrderService,
    ) {
    }

    private function detailRelations(): array
    {
        return [
            'store',
            'user',
            'supplier',
            'purchaseOrder',
            'items.product.category',
            'items.purchaseOrderItem',
            'payments.user',
            'paymentVouchers.payments',
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

        $query = Grn::query()
            ->with([
                'store:store_id,store_name',
                'user:user_id,first_name,last_name,email',
                'supplier:supplier_id,supplier_name,current_balance',
                'purchaseOrder:purchase_order_id,po_number,status',
                'items:grn_item_id,grn_id,po_item_id,product_id,product_name_snapshot,quantity_expected,quantity_accepted,quantity_rejected,free_qty,notes',
            ])
            ->withCount('items')
            ->withSum('items as total_qty_received', 'quantity_accepted')
            ->orderByDesc('grn_id');

        $this->scopeAccessible($query, $user)
            ->when(!empty($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(!empty($filters['status']) && $filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['payment_status']) && $filters['payment_status'] !== 'all', fn ($q) => $q->where('payment_status', $filters['payment_status']))
            ->when(!empty($filters['supplier_id']), fn ($q) => $q->where('supplier_id', (int) $filters['supplier_id']))
            ->when(!empty($filters['purchase_order_id']), fn ($q) => $q->where('purchase_order_id', (int) $filters['purchase_order_id']))
            ->when(!empty($filters['source']) && $filters['source'] !== 'all', function ($q) use ($filters) {
                if ($filters['source'] === 'manual') {
                    $q->whereNull('purchase_order_id');
                    return;
                }

                if ($filters['source'] === 'po') {
                    $q->whereNotNull('purchase_order_id');
                }
            })
            ->when(!empty($filters['release_state']) && $filters['release_state'] !== 'all', function ($q) use ($filters) {
                if ($filters['release_state'] === 'released') {
                    $q->whereNotNull('stock_applied_at');
                    return;
                }

                if ($filters['release_state'] === 'pending') {
                    $q->whereNull('stock_applied_at');
                }
            })
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('grn_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('grn_date', '<=', $filters['date_to']))
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $term = trim((string) $filters['search']);
                $q->where(function ($sub) use ($term) {
                    $sub->where('supplier_name', 'like', "%{$term}%")
                        ->orWhere('invoice_number', 'like', "%{$term}%")
                        ->orWhere('grn_number', 'like', "%{$term}%")
                        ->orWhere('po_number', 'like', "%{$term}%")
                        ->orWhereHas('purchaseOrder', fn ($pq) => $pq->where('po_number', 'like', "%{$term}%"))
                        ->orWhereHas('payments', function ($paymentQuery) use ($term) {
                            $paymentQuery->where('payment_number', 'like', "%{$term}%")
                                ->orWhere('mpesa_code', 'like', "%{$term}%")
                                ->orWhere('bank_reference', 'like', "%{$term}%")
                                ->orWhere('card_reference', 'like', "%{$term}%");
                        });
                });
            });

        return $query->paginate($perPage);
    }

    public function show(User $user, Grn $grn): Grn
    {
        $this->authorizeStoreAccess($user, $grn->store_id);
        return $grn->load($this->detailRelations());
    }

    public function createDraft(User $user, array $data): Grn
    {
        $this->authorizeStoreAccess($user, $data['store_id']);

        return DB::transaction(function () use ($user, $data) {
            $supplier = $this->resolveSupplier($data['supplier_id'] ?? null);
            $purchaseOrderId = $this->resolvePurchaseOrderId($data['purchase_order_id'] ?? null, $data['store_id'], $supplier?->supplier_id);

            $grn = Grn::create([
                'store_id' => (int) $data['store_id'],
                'user_id' => $user->user_id,
                'supplier_id' => $supplier?->supplier_id,
                'purchase_order_id' => $purchaseOrderId,
                'grn_number' => null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'invoice_reference_total' => array_key_exists('invoice_reference_total', $data) ? $data['invoice_reference_total'] : null,
                'grn_date' => $data['grn_date'],
                'supplier_name' => $supplier?->supplier_name ?? ($data['supplier_name'] ?? null),
                'is_po_available' => (bool) $purchaseOrderId,
                'po_number' => $purchaseOrderId ? optional(\App\Models\PurchaseOrder::find($purchaseOrderId))->po_number : null,
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'additional_discount_1' => 0,
                'additional_discount_2' => 0,
                'other_charges' => round((float) ($data['other_charges'] ?? 0), 2),
                'round_off' => 0,
                'grand_total' => 0,
                'final_total' => 0,
                'paid_amount' => 0,
                'balance_due' => 0,
                'payment_status' => 'unpaid',
                'release_to_inventory' => array_key_exists('release_to_inventory', $data) ? (bool) $data['release_to_inventory'] : false,
                'notes' => $data['notes'] ?? null,
            ]);

            $grn->update([
                'grn_number' => 'GRN-' . str_pad((string) $grn->grn_id, 5, '0', STR_PAD_LEFT),
            ]);

            if (!empty($data['items'])) {
                $this->syncItems($grn, $data['items']);
            }

            $grn = $this->recalculateTotals($grn->fresh());
            $this->assertReferenceTotalMatches($grn);
            $this->syncProductMasterCost($grn);

            $this->auditLogService->log(
                'grn.create',
                $grn,
                null,
                $grn->toArray(),
                ['message' => 'GRN draft created'],
                $grn->store_id,
            );

            return $grn->fresh()->load($this->detailRelations());
        });
    }

    public function updateDraft(User $user, Grn $grn, array $data): Grn
    {
        $this->authorizeStoreAccess($user, $grn->store_id);

        if ($grn->status === 'completed') {
            throw new HttpResponseException(response()->json([
                'message' => 'Completed GRN cannot be edited.',
            ], 422));
        }

        return DB::transaction(function () use ($user, $grn, $data) {
            $record = Grn::query()->lockForUpdate()->findOrFail($grn->grn_id);
            $old = $record->toArray();

            if (array_key_exists('supplier_id', $data)) {
                $supplier = $this->resolveSupplier($data['supplier_id']);
                $record->supplier_id = $supplier?->supplier_id;
                $record->supplier_name = $supplier?->supplier_name;
            }

            if (array_key_exists('purchase_order_id', $data)) {
                $purchaseOrderId = $this->resolvePurchaseOrderId($data['purchase_order_id'], $record->store_id, $record->supplier_id);
                $record->purchase_order_id = $purchaseOrderId;
                $record->is_po_available = (bool) $purchaseOrderId;
                $record->po_number = $purchaseOrderId ? optional(\App\Models\PurchaseOrder::find($purchaseOrderId))->po_number : null;
            }

            foreach (['grn_date', 'invoice_number', 'invoice_date', 'invoice_reference_total', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $record->{$field} = $data[$field];
                }
            }

            if (array_key_exists('release_to_inventory', $data)) {
                $record->release_to_inventory = (bool) $data['release_to_inventory'];
            }

            if (array_key_exists('other_charges', $data)) {
                $record->other_charges = round((float) ($data['other_charges'] ?? 0), 2);
            }

            $record->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($record, $data['items']);
            }

            $record = $this->recalculateTotals($record->fresh());
            $this->assertReferenceTotalMatches($record);
            $this->syncProductMasterCost($record);

            $this->auditLogService->log(
                'grn.update',
                $record,
                $old,
                $record->toArray(),
                ['message' => 'GRN draft updated'],
                $record->store_id,
            );

            return $record->fresh()->load($this->detailRelations());
        });
    }

    public function addItem(User $user, Grn $grn, array $data): GrnItem
    {
        $this->authorizeStoreAccess($user, $grn->store_id);
        if ($grn->status === 'completed') {
            throw new HttpResponseException(response()->json(['message' => 'Completed GRN cannot accept new items.'], 422));
        }

        $payload = $this->normalizeItemPayload($grn, $data, (int) (($grn->items()->max('sort_order') ?? 0) + 1));
        $item = $grn->items()->create($payload);
        $this->recalculateTotals($grn->fresh());
        return $item->fresh()->load('product.category');
    }

    public function updateItem(User $user, Grn $grn, GrnItem $item, array $data): GrnItem
    {
        $this->authorizeStoreAccess($user, $grn->store_id);

        if ((int) $item->grn_id !== (int) $grn->grn_id) {
            throw new HttpResponseException(response()->json(['message' => 'Item does not belong to this GRN.'], 422));
        }

        if ($grn->status === 'completed') {
            throw new HttpResponseException(response()->json(['message' => 'Completed GRN items cannot be edited.'], 422));
        }

        $payload = $this->normalizeItemPayload($grn, [...$item->toArray(), ...$data], (int) ($item->sort_order ?: $item->grn_item_id));
        $item->update($payload);
        $this->recalculateTotals($grn->fresh());
        return $item->fresh()->load('product.category');
    }

    public function deleteItem(User $user, Grn $grn, GrnItem $item): void
    {
        $this->authorizeStoreAccess($user, $grn->store_id);
        if ((int) $item->grn_id !== (int) $grn->grn_id) {
            throw new HttpResponseException(response()->json(['message' => 'Item does not belong to this GRN.'], 422));
        }
        if ($grn->status === 'completed') {
            throw new HttpResponseException(response()->json(['message' => 'Completed GRN items cannot be deleted.'], 422));
        }
        $item->delete();
        $this->recalculateTotals($grn->fresh());
    }

    public function recalculateTotals(Grn $grn): Grn
    {
        $grn->load([
            'items' => fn ($q) => $q->whereNull('deleted_at'),
            'payments' => fn ($q) => $q
                ->whereNull('deleted_at')
                ->whereIn('status', ['posted', 'completed']),
        ]);

        $subtotal = (float) $grn->items->sum('taxable_amount');
        $taxAmount = (float) $grn->items->sum('tax_amount');
        $discountAmount = (float) $grn->items->sum('total_discount_amount');
        $grandTotal = $subtotal + $taxAmount + (float) $grn->other_charges;
        $finalTotal = round(max($grandTotal - (float) $grn->additional_discount_1 - (float) $grn->additional_discount_2 + (float) $grn->round_off, 0), 2);
        $paidAmount = round((float) $grn->payments->sum('amount_paid'), 2);
        $balanceDue = round(max($finalTotal - $paidAmount, 0), 2);
        $paymentStatus = $paidAmount <= 0 ? 'unpaid' : ($paidAmount >= $finalTotal ? 'paid' : 'partial');

        $grn->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'grand_total' => round($grandTotal, 2),
            'final_total' => $finalTotal,
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'payment_status' => $paymentStatus,
            'last_payment_at' => $paidAmount > 0 ? optional($grn->payments->sortByDesc('paid_at')->first())->paid_at : null,
        ]);

        return $grn->fresh()->load($this->detailRelations());
    }

    public function charge(User $user, Grn $grn, array $data): Grn
    {
        $this->authorizeStoreAccess($user, $grn->store_id);

        return DB::transaction(function () use ($user, $grn, $data) {
            $record = Grn::query()->lockForUpdate()->findOrFail($grn->grn_id);
            $record = $this->recalculateTotals($record);

            $balanceDue = (float) $record->balance_due;
            if ($balanceDue <= 0) {
                throw new HttpResponseException(response()->json(['message' => 'This GRN is already fully paid.'], 422));
            }

            $voucher = $this->resolveVoucherForPayment($record, $data['payment_voucher_id'] ?? null, $user);

            $amountReceived = round((float) ($data['amount_received'] ?? 0), 2);
            if ($amountReceived <= 0) {
                throw new HttpResponseException(response()->json(['message' => 'Payment amount must be greater than zero.'], 422));
            }

            $voucherBalance = round((float) $voucher->balance_due, 2);
            if ($voucherBalance <= 0) {
                throw new HttpResponseException(response()->json(['message' => 'The selected payment voucher is already fully settled.'], 422));
            }

            $amountTendered = round((float) ($data['amount_tendered'] ?? $amountReceived), 2);
            $amountPaid = round(min($amountReceived, $balanceDue, $voucherBalance), 2);
            $changeReturned = ($data['payment_method'] ?? 'cash') === 'cash' ? round(max($amountTendered - $amountPaid, 0), 2) : 0.0;

            $payment = GrnPayment::create([
                'grn_id' => $record->grn_id,
                'store_id' => $record->store_id,
                'user_id' => $user->user_id,
                'payment_voucher_id' => $voucher->payment_voucher_id,
                'payment_number' => null,
                'payment_voucher_number' => $voucher->voucher_number,
                'payment_method' => $data['payment_method'],
                'status' => 'posted',
                'amount_paid' => $amountPaid,
                'amount_received' => $amountReceived,
                'amount_tendered' => $amountTendered,
                'change_returned' => $changeReturned,
                'mpesa_phone' => $data['mpesa_phone'] ?? null,
                'mpesa_code' => $data['mpesa_code'] ?? null,
                'card_reference' => $data['card_reference'] ?? null,
                'card_holder' => $data['card_holder'] ?? null,
                'bank_reference' => $data['bank_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_at' => now(),
            ]);

            if (!$payment->payment_number) {
                $payment->update([
                    'payment_number' => 'GRNPAY-' . str_pad((string) $payment->grn_payment_id, 6, '0', STR_PAD_LEFT),
                ]);
            }

            $this->applyVoucherSettlement($voucher, $payment, $user);
            $this->recordSupplierPaymentLedger($record, $payment->fresh(), $user, $data['payment_method']);
            $this->refreshSupplierBalance($record->supplier_id);

            $record = $this->recalculateTotals($record->fresh());
            return $record->fresh()->load($this->detailRelations());
        });
    }

    public function recordMpesaSettlement(MpesaTransaction $txn): ?GrnPayment
    {
        if (!$txn->grn_id) {
            return null;
        }

        return DB::transaction(function () use ($txn) {
            $txn = MpesaTransaction::query()->lockForUpdate()->findOrFail($txn->mpesa_transaction_id);
            if ($txn->payment_id) {
                return GrnPayment::query()->find($txn->payment_id);
            }

            $grn = Grn::query()->lockForUpdate()->findOrFail($txn->grn_id);
            $grn = $this->recalculateTotals($grn);
            $balanceDue = (float) $grn->balance_due;
            if ($balanceDue <= 0) {
                return null;
            }

            $user = $txn->user()->first() ?? $grn->user()->first();
            if (!$user) {
                throw new RuntimeException('Unable to determine the user for GRN M-Pesa settlement.');
            }

            $amountReceived = round((float) $txn->amount, 2);
            $amountPaid = round(min($amountReceived, $balanceDue), 2);

            $payment = GrnPayment::create([
                'grn_id' => $grn->grn_id,
                'store_id' => $grn->store_id,
                'user_id' => $user->user_id,
                'payment_number' => null,
                'payment_method' => 'mpesa',
                'status' => 'posted',
                'amount_paid' => $amountPaid,
                'amount_received' => $amountReceived,
                'amount_tendered' => $amountReceived,
                'change_returned' => 0,
                'mpesa_phone' => $txn->phone_number,
                'mpesa_code' => $txn->mpesa_receipt,
                'notes' => 'Auto-posted from M-Pesa callback',
                'paid_at' => $txn->transaction_date ?? now(),
            ]);

            if (!$payment->payment_number) {
                $payment->update([
                    'payment_number' => 'GRNPAY-' . str_pad((string) $payment->grn_payment_id, 6, '0', STR_PAD_LEFT),
                ]);
            }

            $this->recordSupplierPaymentLedger($grn, $payment, $user, 'mpesa');
            $this->refreshSupplierBalance($grn->supplier_id);
            
            return $payment->fresh();
        });
    }

    public function recordB2bVoucherSettlement(MpesaTransaction $txn, PaymentVoucher $voucher): ?GrnPayment
    {
        if (!$txn->grn_id) {
            return null;
        }

        return DB::transaction(function () use ($txn, $voucher) {
            $txn = MpesaTransaction::query()->lockForUpdate()->findOrFail($txn->mpesa_transaction_id);
            if ($txn->payment_id) {
                return GrnPayment::query()->find($txn->payment_id);
            }

            $grn = Grn::query()->lockForUpdate()->findOrFail($txn->grn_id);
            $voucher = PaymentVoucher::query()->lockForUpdate()->findOrFail($voucher->payment_voucher_id);
            $grn = $this->recalculateTotals($grn);

            $balanceDue = (float) $grn->balance_due;
            $voucherBalance = (float) $voucher->balance_due;
            if ($balanceDue <= 0 || $voucherBalance <= 0) {
                return null;
            }

            $user = $txn->user()->first() ?? $grn->user()->first();
            if (!$user) {
                throw new RuntimeException('Unable to determine the user for B2B GRN settlement.');
            }

            $amountReceived = round((float) $txn->amount, 2);
            $amountPaid = round(min($amountReceived, $balanceDue, $voucherBalance), 2);

            $payment = GrnPayment::create([
                'grn_id' => $grn->grn_id,
                'store_id' => $grn->store_id,
                'user_id' => $user->user_id,
                'payment_voucher_id' => $voucher->payment_voucher_id,
                'payment_number' => null,
                'payment_voucher_number' => $voucher->voucher_number,
                'payment_method' => 'mpesa',
                'status' => 'posted',
                'amount_paid' => $amountPaid,
                'amount_received' => $amountReceived,
                'amount_tendered' => $amountReceived,
                'change_returned' => 0,
                'mpesa_phone' => $txn->phone_number,
                'mpesa_code' => $txn->mpesa_receipt,
                'bank_reference' => $txn->conversation_id,
                'notes' => 'Auto-posted from M-Pesa B2B callback',
                'paid_at' => $txn->transaction_date ?? now(),
            ]);

            if (!$payment->payment_number) {
                $payment->update([
                    'payment_number' => 'GRNPAY-' . str_pad((string) $payment->grn_payment_id, 6, '0', STR_PAD_LEFT),
                ]);
            }

            $this->applyVoucherSettlement($voucher, $payment, $user);
            $this->recordSupplierPaymentLedger($grn, $payment->fresh(), $user, 'mpesa');
            $this->refreshSupplierBalance($grn->supplier_id);
            $this->recalculateTotals($grn->fresh());

            return $payment->fresh();
        });
    }

    public function complete(User $user, Grn $grn, array $data = []): Grn
    {
        $this->authorizeStoreAccess($user, $grn->store_id);

        return DB::transaction(function () use ($user, $grn, $data) {
            $record = Grn::query()->with('items')->lockForUpdate()->findOrFail($grn->grn_id);
            $releaseToInventory = array_key_exists('release_to_inventory', $data) ? (bool) $data['release_to_inventory'] : (bool) $record->release_to_inventory;

            if ($record->items->isEmpty()) {
                throw new HttpResponseException(response()->json(['message' => 'Add at least one line before completing the GRN.'], 422));
            }

            if (!$record->supplier_id) {
                throw new HttpResponseException(response()->json(['message' => 'Please assign a supplier before completing the GRN.'], 422));
            }

            $record = $this->recalculateTotals($record);
            $this->assertReferenceTotalMatches($record);
            $this->syncProductMasterCost($record);
            if ($record->status !== 'completed') {
                if ($releaseToInventory && !$record->stock_applied_at) {
                    $this->applyInventoryLayers($user, $record);
                }

                $record->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'release_to_inventory' => $releaseToInventory,
                    'stock_applied_at' => $releaseToInventory ? now() : null,
                    'notes' => $data['notes'] ?? $record->notes,
                ]);

                $this->recordSupplierInvoiceLedger($record, $user);
            } elseif ($releaseToInventory && !$record->stock_applied_at) {
                $this->applyInventoryLayers($user, $record);
                $record->update([
                    'release_to_inventory' => true,
                    'stock_applied_at' => now(),
                ]);
            }

            if ($record->purchase_order_id && !$record->po_reconciled_at) {
                $this->purchaseOrderService->applyReceiptFromGrn($record->loadMissing('items'));
                $record->update(['po_reconciled_at' => now()]);
            }

            $this->refreshSupplierBalance($record->supplier_id);
            return $record->fresh()->load($this->detailRelations());
        });
    }

    public function deleteDraft(User $user, Grn $grn): void
    {
        $this->authorizeStoreAccess($user, $grn->store_id);
        if ($grn->status === 'completed') {
            throw new HttpResponseException(response()->json(['message' => 'Completed GRN cannot be deleted.'], 422));
        }
        $grn->delete();
    }

    private function syncItems(Grn $grn, array $items): void
    {
        $existing = $grn->items()->get()->keyBy('grn_item_id');
        $keep = [];

        foreach (array_values($items) as $index => $itemData) {
            $payload = $this->normalizeItemPayload($grn, $itemData, $index + 1);
            $itemId = (int) ($itemData['grn_item_id'] ?? 0);

            if ($itemId && $existing->has($itemId)) {
                $existing->get($itemId)->update($payload);
                $keep[] = $itemId;
                continue;
            }

            $created = $grn->items()->create($payload);
            $keep[] = $created->grn_item_id;
        }

        if (!empty($keep)) {
            $grn->items()->whereNotIn('grn_item_id', $keep)->delete();
        }
    }

    private function assertReferenceTotalMatches(Grn $grn): void
    {
        if ($grn->invoice_reference_total === null || $grn->invoice_reference_total === '') {
            return;
        }

        $reference = round((float) $grn->invoice_reference_total, 2);
        $calculated = round((float) $grn->final_total, 2);

        if (abs($reference - $calculated) >= 0.01) {
            throw new HttpResponseException(response()->json([
                'message' => sprintf(
                    'Calculated GRN total (%0.2f) does not match the delivery note reference total (%0.2f). Review quantities, costs, or landed charges before saving.',
                    $calculated,
                    $reference,
                ),
            ], 422));
        }
    }

    private function syncProductMasterCost(Grn $grn): void
    {
        $items = $grn->relationLoaded('items') ? $grn->items : $grn->items()->get();

        foreach ($items as $item) {
            if (!$item->product_id) {
                continue;
            }

            Product::query()
                ->whereKey($item->product_id)
                ->update([
                    'cost_price' => round((float) ($item->cost_price_excl_tax ?? 0), 2),
                ]);
        }
    }

    private function normalizeItemPayload(Grn $grn, array $data, int $sortOrder): array
    {
        $product = \App\Models\Product::query()->findOrFail((int) $data['product_id']);
        $poItem = !empty($data['po_item_id']) ? PurchaseOrderItem::query()->find($data['po_item_id']) : null;
        
        $quantityExpected = max((int) ($data['quantity_expected'] ?? ($poItem?->quantity_remaining ?? $poItem?->quantity_ordered ?? 0)), 0);
        $accepted = max((int) ($data['quantity_accepted'] ?? $data['qty_received'] ?? 0), 0);
        $rejected = max((int) ($data['quantity_rejected'] ?? 0), 0);

        if ($quantityExpected > 0 && ($accepted + $rejected) > $quantityExpected) {
            throw new HttpResponseException(response()->json([
                'message' => 'Accepted plus rejected quantity cannot exceed the expected quantity for a GRN line.',
            ], 422));
        }

        $costExcl = round((float) ($data['cost_price_excl_tax'] ?? $poItem?->unit_cost ?? $product->cost_price ?? 0), 2);
        $taxRate = round((float) ($data['tax_rate'] ?? $poItem?->tax_rate ?? 0), 2);
        $costIncl = round((float) ($data['cost_price_incl_tax'] ?? ($costExcl * (1 + ($taxRate / 100)))), 2);
        $taxableAmount = round($accepted * $costExcl, 2);
        $taxAmount = round($accepted * max($costIncl - $costExcl, 0), 2);
        $totalAmount = round($accepted * $costIncl, 2);

        return [
            'po_item_id' => $poItem?->purchase_order_item_id,
            'product_id' => $product->product_id,
            'product_name_snapshot' => $data['product_name_snapshot'] ?? $product->product_name,
            'barcode' => $data['barcode'] ?? $product->sku,
            'batch_no' => !empty($data['batch_no']) ? trim((string) $data['batch_no']) : $this->makeTraceabilityCode($grn, $sortOrder),
            'quantity_expected' => $quantityExpected,
            'qty_received' => $accepted,
            'quantity_accepted' => $accepted,
            'quantity_rejected' => $rejected,
            'free_qty' => max((int) ($data['free_qty'] ?? 0), 0),
            'cost_price_excl_tax' => $costExcl,
            'cost_price_incl_tax' => $costIncl,
            'tax_rate' => $taxRate,
            'taxable_amount' => $taxableAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'mrp' => round((float) ($data['mrp'] ?? $product->price ?? 0), 2),
            'selling_price' => round((float) ($data['selling_price'] ?? $product->price ?? 0), 2),
            'low_inventory_level' => (int) ($data['low_inventory_level'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'sort_order' => $sortOrder,
        ];
    }

    private function applyInventoryLayers(User $user, Grn $grn): void
    {
        $items = $grn->relationLoaded('items') ? $grn->items : $grn->items()->get();

        foreach ($items as $index => $item) {
            $accepted = (int) ($item->quantity_accepted ?? $item->qty_received ?? 0);
            $freeQty = (int) ($item->free_qty ?? 0);
            $quantityToPost = $accepted + $freeQty;
            if ($quantityToPost <= 0) {
                continue;
            }

            $sequence = (int) ($item->sort_order ?: ($index + 1));
            $traceabilityCode = $item->batch_no ?: $this->makeTraceabilityCode($grn, $sequence);
            if (!$item->batch_no) {
                $item->update(['batch_no' => $traceabilityCode]);
            }

            $this->inventoryService->create($user, [
                'store_id' => $grn->store_id,
                'product_id' => $item->product_id,
                'batch_no' => $traceabilityCode,
                'quantity' => $quantityToPost,
                'reorder_level' => (int) ($item->low_inventory_level ?? 0),
            ]);
        }
    }

    private function resolveVoucherForPayment(Grn $grn, int|string|null $paymentVoucherId, User $user): PaymentVoucher
    {
        $voucher = PaymentVoucher::query()->lockForUpdate()->find((int) $paymentVoucherId);

        if (!$voucher) {
            throw new HttpResponseException(response()->json([
                'message' => 'A valid payment voucher is required before processing supplier payment.',
            ], 422));
        }

        $this->authorizeStoreAccess($user, $voucher->store_id);

        if ((int) $voucher->grn_id !== (int) $grn->grn_id) {
            throw new HttpResponseException(response()->json([
                'message' => 'The selected payment voucher does not belong to this GRN.',
            ], 422));
        }

        $voucherStatus = strtolower((string) $voucher->status);
        $voucherStatus = $voucherStatus === 'approved' ? 'authorized' : $voucherStatus;

        if (in_array($voucherStatus, ['paid', 'cancelled'], true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'The selected payment voucher cannot accept new payments.',
            ], 422));
        }

        if (!in_array($voucherStatus, ['authorized', 'partial', 'partially_paid'], true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Only authorized payment vouchers can be settled. Submit the voucher for approval first.',
            ], 422));
        }

        return $voucher;
    }

    private function applyVoucherSettlement(PaymentVoucher $voucher, GrnPayment $payment, User $user): PaymentVoucher
    {
        $paidAmount = round((float) $voucher->paid_amount + (float) $payment->amount_paid, 2);
        $balanceDue = round(max((float) $voucher->amount - $paidAmount, 0), 2);
        $status = $balanceDue <= 0 ? 'paid' : 'partially_paid';

        $voucher->update([
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'status' => $status,
            'approved_by_user_id' => $user->user_id,
        ]);

        return $voucher->fresh();
    }

    private function recordSupplierInvoiceLedger(Grn $grn, User $user): void
    {
        SupplierLedgerEntry::query()->firstOrCreate(
            [
                'grn_id' => $grn->grn_id,
                'entry_type' => 'invoice',
            ],
            [
                'supplier_id' => $grn->supplier_id,
                'store_id' => $grn->store_id,
                'purchase_order_id' => $grn->purchase_order_id,
                'created_by_user_id' => $user->user_id,
                'direction' => 'debit',
                'reference_number' => $grn->grn_number,
                'description' => 'GRN invoice posted · ' . $grn->grn_number,
                'amount' => (float) $grn->final_total,
                'entry_date' => now(),
                'meta' => ['invoice_number' => $grn->invoice_number],
            ]
        );
    }

    private function recordSupplierPaymentLedger(Grn $grn, GrnPayment $payment, User $user, string $method): void
    {
        SupplierLedgerEntry::query()->firstOrCreate(
            [
                'grn_payment_id' => $payment->grn_payment_id,
                'entry_type' => 'payment',
            ],
            [
                'supplier_id' => $grn->supplier_id,
                'store_id' => $grn->store_id,
                'purchase_order_id' => $grn->purchase_order_id,
                'grn_id' => $grn->grn_id,
                'created_by_user_id' => $user->user_id,
                'direction' => 'credit',
                'reference_number' => $payment->payment_number,
                'description' => 'Supplier payment via ' . strtoupper($method) . ' · ' . $grn->grn_number . ($payment->payment_voucher_number ? ' · Voucher ' . $payment->payment_voucher_number : ''),
                'amount' => (float) $payment->amount_paid,
                'entry_date' => $payment->paid_at ?? now(),
                'meta' => [
                    'payment_method' => $method,
                    'payment_voucher_id' => $payment->payment_voucher_id,
                    'payment_voucher_number' => $payment->payment_voucher_number,
                ],
            ]
        );
    }

    private function refreshSupplierBalance(int|string|null $supplierId): void
    {
        if (!$supplierId) {
            return;
        }

        $supplier = Supplier::query()->find($supplierId);
        if ($supplier) {
            $supplier->refreshCurrentBalance();
        }
    }

    private function resolveSupplier(int|string|null $supplierId): ?Supplier
    {
        if (!$supplierId) {
            return null;
        }

        $supplier = Supplier::query()->find((int) $supplierId);
        if (!$supplier) {
            throw new HttpResponseException(response()->json([
                'message' => 'The selected supplier could not be found in the supplier master.',
            ], 422));
        }

        return $supplier;
    }

    private function resolvePurchaseOrderId(int|string|null $purchaseOrderId, int|string $storeId, int|string|null $supplierId): ?int
    {
        if (!$purchaseOrderId) {
            return null;
        }

        $order = \App\Models\PurchaseOrder::query()->find((int) $purchaseOrderId);
        if (!$order) {
            throw new HttpResponseException(response()->json(['message' => 'The selected purchase order could not be found.'], 422));
        }

        if ((int) $order->store_id !== (int) $storeId) {
            throw new HttpResponseException(response()->json(['message' => 'The selected purchase order belongs to another store.'], 422));
        }

        if ($supplierId && (int) $order->supplier_id !== (int) $supplierId) {
            throw new HttpResponseException(response()->json(['message' => 'The selected purchase order does not belong to the chosen supplier.'], 422));
        }

        return $order->purchase_order_id;
    }

    private function makeTraceabilityCode(Grn $grn, int $lineNumber): string
    {
        return sprintf('GRN-%05d-%d', (int) $grn->grn_id, max($lineNumber, 1));
    }
}
