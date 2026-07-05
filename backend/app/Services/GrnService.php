<?php

namespace App\Services;

use App\Models\Grn;
use App\Models\GrnItem;
use App\Models\GrnPayment;
use App\Models\MpesaTransaction;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Illuminate\Support\Facades\Schema;

class GrnService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    private function detailRelations(): array
    {
        return [
            'store',
            'user',
            'supplier',
            'items.product.category',
            'payments.user',
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
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));

        $query = Grn::query()
            ->with([
                'store:store_id,store_name',
                'user:user_id,first_name,last_name,email',
                'supplier:supplier_id,supplier_name',
                'items:grn_item_id,grn_id,notes',
            ])
            ->withCount('items')
            ->withSum('items as total_qty', 'qty_received')
            ->orderByDesc('grn_id');

        $this->scopeAccessible($query, $user)
            ->when(!empty($filters['store_id']), fn ($q) => $q->where('store_id', (int) $filters['store_id']))
            ->when(!empty($filters['status']) && $filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['payment_status']) && $filters['payment_status'] !== 'all', fn ($q) => $q->where('payment_status', $filters['payment_status']))
            ->when(!empty($filters['stock_release']) && $filters['stock_release'] !== 'all', function ($q) use ($filters) {
                if ($filters['stock_release'] === 'released') {
                    $q->whereNotNull('stock_applied_at');
                }
                if ($filters['stock_release'] === 'hold') {
                    $q->whereNull('stock_applied_at');
                }
            })
            ->when(!empty($filters['supplier_id']), fn ($q) => $q->where('supplier_id', (int) $filters['supplier_id']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('grn_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('grn_date', '<=', $filters['date_to']))
            ->when(isset($filters['item_count_min']) && $filters['item_count_min'] !== '', fn ($q) => $q->has('items', '>=', (int) $filters['item_count_min']))
            ->when(isset($filters['item_count_max']) && $filters['item_count_max'] !== '', fn ($q) => $q->has('items', '<=', (int) $filters['item_count_max']))
            ->when(isset($filters['value_min']) && $filters['value_min'] !== '', fn ($q) => $q->whereRaw('COALESCE(final_total, grand_total, 0) >= ?', [(float) $filters['value_min']]))
            ->when(isset($filters['value_max']) && $filters['value_max'] !== '', fn ($q) => $q->whereRaw('COALESCE(final_total, grand_total, 0) <= ?', [(float) $filters['value_max']]))
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $term = trim((string) $filters['search']);
                $q->where(function ($sub) use ($term) {
                    $sub->where('supplier_name', 'like', "%{$term}%")
                        ->orWhere('invoice_number', 'like', "%{$term}%")
                        ->orWhere('grn_number', 'like', "%{$term}%")
                        ->orWhere('po_number', 'like', "%{$term}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$term}%"));
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
        $supplier = $this->resolveSupplier($data['supplier_id'] ?? null);

        return DB::transaction(function () use ($user, $data, $supplier) {
            $grn = Grn::create([
                'store_id' => (int) $data['store_id'],
                'user_id' => $user->user_id,
                'supplier_id' => $supplier?->supplier_id,
                'grn_number' => null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'grn_date' => $data['grn_date'],
                'supplier_name' => $supplier?->supplier_name ?? ($data['supplier_name'] ?? null),
                'is_po_available' => (bool) ($data['is_po_available'] ?? false),
                'po_number' => $data['po_number'] ?? null,
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'additional_discount_1' => $data['additional_discount_1'] ?? 0,
                'additional_discount_2' => $data['additional_discount_2'] ?? 0,
                'other_charges' => $data['other_charges'] ?? 0,
                'round_off' => $data['round_off'] ?? 0,
                'grand_total' => 0,
                'final_total' => 0,
                'paid_amount' => 0,
                'balance_due' => 0,
                'payment_status' => 'unpaid',
                'release_to_inventory' => array_key_exists('release_to_inventory', $data)
                    ? (bool) $data['release_to_inventory']
                    : true,
                'notes' => $paymentNotes !== '' ? $paymentNotes : null,
            ]);

            if (!$grn->grn_number) {
                $grn->update([
                    'grn_number' => 'GRN-' . str_pad((string) $grn->grn_id, 5, '0', STR_PAD_LEFT),
                ]);
            }

            $grn = $this->recalculateTotals($grn->fresh());

            $this->auditLogService->log(
                'grn.create',
                $grn,
                null,
                $grn->toArray(),
                ['message' => 'GRN draft created'],
                $grn->store_id
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

        $old = $grn->toArray();
        $supplier = array_key_exists('supplier_id', $data)
            ? $this->resolveSupplier($data['supplier_id'])
            : null;

        $payload = [
            'invoice_number' => array_key_exists('invoice_number', $data) ? $data['invoice_number'] : $grn->invoice_number,
            'invoice_date' => array_key_exists('invoice_date', $data) ? $data['invoice_date'] : $grn->invoice_date,
            'grn_date' => array_key_exists('grn_date', $data) ? $data['grn_date'] : $grn->grn_date,
            'is_po_available' => array_key_exists('is_po_available', $data) ? (bool) $data['is_po_available'] : $grn->is_po_available,
            'po_number' => array_key_exists('po_number', $data) ? $data['po_number'] : $grn->po_number,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $grn->notes,
            'additional_discount_1' => array_key_exists('additional_discount_1', $data) ? $data['additional_discount_1'] : $grn->additional_discount_1,
            'additional_discount_2' => array_key_exists('additional_discount_2', $data) ? $data['additional_discount_2'] : $grn->additional_discount_2,
            'other_charges' => array_key_exists('other_charges', $data) ? $data['other_charges'] : $grn->other_charges,
            'round_off' => array_key_exists('round_off', $data) ? $data['round_off'] : $grn->round_off,
            'release_to_inventory' => array_key_exists('release_to_inventory', $data)
                ? (bool) $data['release_to_inventory']
                : $grn->release_to_inventory,
        ];

        if ($supplier) {
            $payload['supplier_id'] = $supplier->supplier_id;
            $payload['supplier_name'] = $supplier->supplier_name;
        } elseif (array_key_exists('supplier_name', $data)) {
            $payload['supplier_name'] = $data['supplier_name'];
        }

        $grn->update($payload);
        $grn = $this->recalculateTotals($grn->fresh());

        $this->auditLogService->log(
            'grn.update',
            $grn,
            $old,
            $grn->toArray(),
            ['message' => 'GRN draft updated'],
            $grn->store_id
        );

        return $grn->fresh()->load($this->detailRelations());
    }

    public function addItem(User $user, Grn $grn, array $data): GrnItem
    {
        $this->authorizeStoreAccess($user, $grn->store_id);

        if ($grn->status === 'completed') {
            throw new HttpResponseException(response()->json(['message' => 'Completed GRN cannot accept new items.'], 422));
        }

        $nextSort = (int) ($grn->items()->max('sort_order') ?? 0) + 1;
        $data['batch_no'] = !empty($data['batch_no'])
            ? trim((string) $data['batch_no'])
            : $this->makeTraceabilityCode($grn, $nextSort);

        $item = $grn->items()->create([
            ...$data,
            'sort_order' => $nextSort,
        ]);

        $this->recalculateTotals($grn->fresh());

        $this->auditLogService->log(
            'grn_item.create',
            $item,
            null,
            $item->fresh()->toArray(),
            ['grn_number' => $grn->grn_number],
            $grn->store_id
        );

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

        $old = $item->toArray();
        if (array_key_exists('batch_no', $data) && empty($data['batch_no'])) {
            $data['batch_no'] = $this->makeTraceabilityCode($grn, (int) ($item->sort_order ?: $item->grn_item_id));
        }

        $item->update($data);
        $this->recalculateTotals($grn->fresh());

        $this->auditLogService->log(
            'grn_item.update',
            $item,
            $old,
            $item->fresh()->toArray(),
            ['grn_number' => $grn->grn_number],
            $grn->store_id
        );

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

        $old = $item->toArray();
        $item->delete();
        $this->recalculateTotals($grn->fresh());

        $this->auditLogService->log(
            'grn_item.delete',
            $item,
            $old,
            null,
            ['grn_number' => $grn->grn_number],
            $grn->store_id
        );
    }

    private function resolvePaymentStatus(float $finalTotal, float $paidAmount): string
    {
        if ($paidAmount <= 0) {
            return 'unpaid';
        }

        if ($paidAmount >= $finalTotal) {
            return 'paid';
        }

        return 'partial';
    }

    private function resolveSettlementType(array $data, float $balanceDue): string
    {
        $explicit = strtolower((string) ($data['settlement_type'] ?? ''));
        if (in_array($explicit, ['partial', 'immediate'], true)) {
            return $explicit;
        }

        $amountReceived = round((float) ($data['amount_received'] ?? 0), 2);
        return $amountReceived > 0 && $amountReceived < $balanceDue ? 'partial' : 'immediate';
    }

    public function recalculateTotals(Grn $grn): Grn
    {
        $grn->load([
            'items' => fn ($q) => $q->whereNull('deleted_at'),
            'payments' => fn ($q) => $q->whereNull('deleted_at')->where('status', 'posted'),
        ]);

        $subtotal = (float) $grn->items->sum('taxable_amount');
        $taxAmount = (float) $grn->items->sum('tax_amount');
        $discountAmount = (float) $grn->items->sum('total_discount_amount');
        $grandTotal = $subtotal + $taxAmount + (float) $grn->other_charges;
        $finalTotal = $grandTotal
            - (float) $grn->additional_discount_1
            - (float) $grn->additional_discount_2
            + (float) $grn->round_off;

        $paidAmount = (float) $grn->payments->sum('amount_paid');
        $finalTotal = round(max($finalTotal, 0), 2);
        $paidAmount = round(max($paidAmount, 0), 2);
        $balanceDue = round(max($finalTotal - $paidAmount, 0), 2);
        $paymentStatus = $this->resolvePaymentStatus($finalTotal, $paidAmount);

        $grn->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'grand_total' => round($grandTotal, 2),
            'final_total' => $finalTotal,
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'payment_status' => $paymentStatus,
            'last_payment_at' => $paidAmount > 0
                ? optional($grn->payments->sortByDesc('paid_at')->first())->paid_at
                : null,
        ]);

        return $grn->fresh()->load($this->detailRelations());
    }

    public function charge(User $user, Grn $grn, array $data): Grn
    {
        $this->authorizeStoreAccess($user, $grn->store_id);

        return DB::transaction(function () use ($user, $grn, $data) {
            $grn = Grn::query()->lockForUpdate()->findOrFail($grn->grn_id);
            $grn = $this->recalculateTotals($grn);

            $balanceDue = (float) $grn->balance_due;
            if ($balanceDue <= 0) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This GRN is already fully paid.',
                ], 422));
            }

            $settlementType = $this->resolveSettlementType($data, $balanceDue);
            $amountReceived = round((float) ($data['amount_received'] ?? 0), 2);

            if ($settlementType === 'immediate' && $amountReceived <= 0) {
                $amountReceived = round($balanceDue, 2);
            }

            if ($amountReceived <= 0) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Payment amount must be greater than zero.',
                ], 422));
            }

            $amountTendered = round((float) ($data['amount_tendered'] ?? $amountReceived), 2);
            $amountPaid = round(min($amountReceived, $balanceDue), 2);
            $changeReturned = ($data['payment_method'] ?? 'cash') === 'cash'
                ? round(max($amountTendered - $amountPaid, 0), 2)
                : 0.0;

            $paymentNotes = trim(implode(' · ', array_filter([
                $settlementType === 'partial' ? 'Partial payment' : 'Full settlement',
                $data['notes'] ?? null,
            ])));

            $payment = GrnPayment::create([
                'grn_id' => $grn->grn_id,
                'store_id' => $grn->store_id,
                'user_id' => $user->user_id,
                'payment_number' => null,
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
                'notes' => $paymentNotes !== '' ? $paymentNotes : null,
                'paid_at' => now(),
            ]);

            if (!$payment->payment_number) {
                $payment->update([
                    'payment_number' => 'GRNPAY-' . str_pad((string) $payment->grn_payment_id, 6, '0', STR_PAD_LEFT),
                ]);
            }

            $grn = $this->recalculateTotals($grn->fresh());
            $this->syncSupplierOutstanding($grn->supplier_id);

            $this->auditLogService->log(
                'grn.payment.create',
                $payment,
                null,
                $payment->fresh()->toArray(),
                ['grn_number' => $grn->grn_number],
                $grn->store_id
            );

            return $grn->fresh()->load($this->detailRelations());
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

            $grn = Grn::query()->with('items')->lockForUpdate()->findOrFail($txn->grn_id);
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
                'notes' => trim('Auto-posted from M-Pesa callback' . ($txn->account_reference ? ' [' . $txn->account_reference . ']' : '')),
                'paid_at' => $txn->transaction_date ?? now(),
            ]);

            if (!$payment->payment_number) {
                $payment->update([
                    'payment_number' => 'GRNPAY-' . str_pad((string) $payment->grn_payment_id, 6, '0', STR_PAD_LEFT),
                ]);
            }

            $grn = $this->recalculateTotals($grn->fresh());
            $this->syncSupplierOutstanding($grn->supplier_id);

            $this->auditLogService->log(
                'grn.payment.auto_posted',
                $payment,
                null,
                $payment->fresh()->toArray(),
                [
                    'grn_number' => $grn->grn_number,
                    'mpesa_transaction_id' => $txn->mpesa_transaction_id,
                    'mpesa_receipt' => $txn->mpesa_receipt,
                ],
                $grn->store_id
            );

            return $payment->fresh();
        });
    }

    public function complete(User $user, Grn $grn, array $data = []): Grn
    {
        $this->authorizeStoreAccess($user, $grn->store_id);

        return DB::transaction(function () use ($user, $grn, $data) {
            $grn = Grn::query()->with('items')->lockForUpdate()->findOrFail($grn->grn_id);
            $releaseToInventory = array_key_exists('release_to_inventory', $data)
                ? (bool) $data['release_to_inventory']
                : (bool) $grn->release_to_inventory;

            if ($grn->status === 'completed') {
                $updates = [
                    'release_to_inventory' => $releaseToInventory,
                    'notes' => $data['notes'] ?? $grn->notes,
                ];

                if ($releaseToInventory && !$grn->stock_applied_at) {
                    $this->applyInventoryLayers($user, $grn);
                    $updates['stock_applied_at'] = now();
                }

                $grn->update($updates);
                return $grn->fresh()->load($this->detailRelations());
            }

            if ($grn->items->isEmpty()) {
                throw new HttpResponseException(response()->json(['message' => 'Add at least one line before completing the GRN.'], 422));
            }

            if (!$grn->supplier_id) {
                throw new HttpResponseException(response()->json(['message' => 'Please assign a supplier before completing the GRN.'], 422));
            }

            $grn = $this->recalculateTotals($grn);
            $old = $grn->toArray();

            if ($releaseToInventory) {
                $this->applyInventoryLayers($user, $grn);
            }

            $grn->update([
                'status' => 'completed',
                'completed_at' => now(),
                'release_to_inventory' => $releaseToInventory,
                'stock_applied_at' => $releaseToInventory ? now() : null,
                'notes' => $data['notes'] ?? $grn->notes,
            ]);

            $this->syncSupplierOutstanding($grn->supplier_id);

            $this->auditLogService->log(
                'grn.complete',
                $grn,
                $old,
                $grn->fresh()->toArray(),
                ['message' => $releaseToInventory ? 'GRN completed and stock posted' : 'GRN completed without posting stock'],
                $grn->store_id
            );

            return $grn->fresh()->load($this->detailRelations());
        });
    }

    public function deleteDraft(User $user, Grn $grn): void
    {
        $this->authorizeStoreAccess($user, $grn->store_id);

        if ($grn->status === 'completed') {
            throw new HttpResponseException(response()->json(['message' => 'Completed GRN cannot be deleted.'], 422));
        }

        $old = $grn->toArray();
        $grn->delete();

        $this->auditLogService->log(
            'grn.delete',
            $grn,
            $old,
            null,
            ['message' => 'GRN draft deleted'],
            $grn->store_id
        );
    }

    private function applyInventoryLayers(User $user, Grn $grn): void
    {
        $items = $grn->relationLoaded('items') ? $grn->items : $grn->items()->get();

        foreach ($items as $index => $item) {
            $sequence = (int) ($item->sort_order ?: ($index + 1));
            $traceabilityCode = $item->batch_no ?: $this->makeTraceabilityCode($grn, $sequence);

            if (!$item->batch_no) {
                $item->update(['batch_no' => $traceabilityCode]);
            }

            $this->inventoryService->create($user, [
                'store_id' => $grn->store_id,
                'product_id' => $item->product_id,
                'batch_no' => $traceabilityCode,
                'quantity' => (int) $item->qty_received + (int) $item->free_qty,
                'reorder_level' => (int) ($item->low_inventory_level ?? 0),
            ]);
        }
    }

    private function makeTraceabilityCode(Grn $grn, int $lineNumber): string
    {
        return sprintf('GRN-%05d-%d', (int) $grn->grn_id, max($lineNumber, 1));
    }

    private function syncSupplierOutstanding(int|string|null $supplierId): void
    {
        if (!$supplierId || !Schema::hasColumn('suppliers', 'outstanding_balance')) {
            return;
        }

        $supplier = Supplier::query()->find($supplierId);
        if (!$supplier) {
            return;
        }

        $outstandingBalance = (float) Grn::query()
            ->where('supplier_id', $supplierId)
            ->where('status', 'completed')
            ->sum('balance_due');

        $outstandingBalance += (float) $supplier->opening_balance;

        Supplier::query()
            ->where('supplier_id', $supplierId)
            ->update(['outstanding_balance' => round($outstandingBalance, 2)]);
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
}
