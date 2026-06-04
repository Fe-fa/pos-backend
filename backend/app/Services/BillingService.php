<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
        private readonly InventoryService $inventoryService,
    ) {
    }

    // Public so the controller can load store IDs for tenant matching
    public function allowedStoreIds(User $user)
    {
        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->unique()
            ->values();
    }

    // Public so the controller can securely append access bounds to the base query
    public function scopeAccessible(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $storeIds = $this->allowedStoreIds($user);

        if ($user->isManager()) {
            return $query->whereIn('store_id', $storeIds);
        }

        return $query
            ->whereIn('store_id', $storeIds)
            ->where('user_id', $user->user_id);
    }

    // Public so the controller can evaluate explicit store parameters
    public function authorizeStoreAccess(User $user, int|string|null $storeId): void
    {
        if (!$storeId || $user->isAdmin()) {
            return;
        }

        $allowed = $this->allowedStoreIds($user)->map(fn ($id) => (string) $id)->all();

        if (!in_array((string) $storeId, $allowed, true)) {
            abort(response()->json([
                'message' => 'You are not allowed to access this store.',
            ], 403));
        }
    }

    public function authorizeBillingAccess(Billing $billing, ?User $actor = null): void
    {
        $actor = $actor ?: auth()->user();

        if (!$actor) {
            abort(response()->json([
                'message' => 'Unauthenticated.',
            ], 401));
        }

        if ($actor->isAdmin()) {
            return;
        }

        $storeIds = $this->allowedStoreIds($actor)->map(fn ($id) => (string) $id)->all();
        $hasStoreAccess = in_array((string) $billing->store_id, $storeIds, true);

        if ($actor->isManager()) {
            if (!$hasStoreAccess) {
                abort(response()->json([
                    'message' => 'You are not allowed to access this billing.',
                ], 403));
            }

            return;
        }

        $ownsBilling = (string) $billing->user_id === (string) $actor->user_id;

        if (!$hasStoreAccess || !$ownsBilling) {
            abort(response()->json([
                'message' => 'You are not allowed to access this billing.',
            ], 403));
        }
    }

    public function createDraft(User $user, array $data): Billing
    {
        $this->authorizeStoreAccess($user, $data['store_id']);

        $billing = Billing::create([
            'store_id' => $data['store_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'user_id' => $user->user_id,
            'invnumber' => null,
            'status' => 'unpaid',
            'subtotal' => 0,
            'vat_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance_due' => 0,
            'is_draft' => true,
            'billing_date' => now(),
            'notes' => $data['notes'] ?? null,
            'fulfillment_status' => $data['fulfillment_status'] ?? 'pending',
            'fulfillment_type' => $data['fulfillment_type'] ?? 'walk_in_counter',
        ]);

        $this->auditLogService->log(
            'billing.create_draft',
            $billing,
            null,
            $billing->toArray(),
            ['message' => 'Draft billing created'],
            $billing->store_id
        );

        return $billing->load(['customer', 'store', 'items']);
    }

    public function show(Billing $billing): Billing
    {
        $this->authorizeBillingAccess($billing);

        $billing->load(['customer', 'store', 'user', 'items.product.category', 'payments']);
        $billing->loadCount('items');
        $billing->loadSum('items', 'quantity');

        $this->auditLogService->log(
            'billing.view',
            $billing,
            null,
            null,
            ['message' => 'Billing accessed'],
            $billing->store_id
        );

        return $billing;
    }

    public function updateHeader(Billing $billing, array $data): Billing
    {
        $this->authorizeBillingAccess($billing);

        $old = $billing->toArray();

        $billing->update([
            'customer_id' => array_key_exists('customer_id', $data)
                ? $data['customer_id']
                : $billing->customer_id,
            'notes' => array_key_exists('notes', $data)
                ? $data['notes']
                : $billing->notes,
            'fulfillment_status' => array_key_exists('fulfillment_status', $data)
                ? $data['fulfillment_status']
                : $billing->fulfillment_status,
            'fulfillment_type' => array_key_exists('fulfillment_type', $data)
                ? $data['fulfillment_type']
                : $billing->fulfillment_type,
        ]);

        $this->auditLogService->log(
            'billing.update',
            $billing,
            $old,
            $billing->fresh()->toArray(),
            ['message' => 'Billing header updated'],
            $billing->store_id
        );

        return $billing->fresh()->load(['customer', 'store', 'items.product', 'payments']);
    }

    public function recalculateTotals(Billing $billing): Billing
    {
        $billing->load('items');

        $subtotal = (float) $billing->items->sum('line_subtotal');
        $vatAmount = (float) $billing->items->sum('vat_amount');
        $total = $subtotal + $vatAmount;
        $paidAmount = (float) $billing->payments()->sum('amount_received');
        $balanceDue = max($total - $paidAmount, 0);

        $billing->update([
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
        ]);

        return $billing->fresh(['items.product', 'payments']);
    }

    public function finalizeIfNeeded(Billing $billing, User $user): Billing
    {
        $this->authorizeBillingAccess($billing, $user);

        return DB::transaction(function () use ($billing, $user) {
            $billing = Billing::query()
                ->whereKey($billing->billing_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($billing->stock_applied_at) {
                return $billing->fresh()->load(['customer', 'store', 'user', 'items.product', 'payments']);
            }

            $billing = $this->recalculateTotals($billing);
            $billing->load(['items.product', 'payments']);

            if ($billing->items->isEmpty()) {
                abort(response()->json([
                    'message' => 'Cannot finalize billing without items.',
                ], 422));
            }

            $invoiceNumber = $billing->invnumber ?: $this->documentNumberService->nextNumber(
                $billing->store_id,
                'Invoice'
            );

            foreach ($billing->items as $item) {
                $this->inventoryService->consumeFifo(
                    user: $user,
                    storeId: (int) $billing->store_id,
                    productId: (int) $item->product_id,
                    quantity: (int) $item->quantity,
                    reason: 'Billing finalized sale',
                    reference: $invoiceNumber
                );
            }

            $billing->update([
                'is_draft' => false,
                'status' => ((float) $billing->balance_due <= 0) ? 'paid' : 'unpaid',
                'invnumber' => $invoiceNumber,
                'stock_applied_at' => now(),
            ]);

            $this->auditLogService->log(
                'billing.finalize',
                $billing,
                null,
                $billing->fresh()->toArray(),
                ['message' => 'Draft converted to live billing using FIFO stock deduction'],
                $billing->store_id
            );

            return $billing->fresh()->load(['customer', 'store', 'user', 'items.product', 'payments']);
        });
    }

    public function destroy(Billing $billing): void
    {
        $this->authorizeBillingAccess($billing);

        if (!$billing->is_draft) {
            abort(response()->json([
                'message' => 'Only draft billings can be deleted from cashier POS.',
            ], 422));
        }

        if ($billing->payments()->exists()) {
            abort(response()->json([
                'message' => 'Cannot delete billing with payments.',
            ], 422));
        }

        $old = $billing->load('items')->toArray();

        $billing->delete();

        $this->auditLogService->log(
            'billing.delete',
            null,
            $old,
            null,
            ['message' => 'Billing soft deleted'],
            $old['store_id'] ?? null
        );
    }

    public function restore(int|string $billingId, User $user): Billing
    {
        $billing = Billing::onlyTrashed()->find($billingId);

        if (!$billing) {
            abort(response()->json([
                'message' => "Billing record #{$billingId} was not found in trash.",
            ], 404));
        }

        $this->authorizeBillingAccess($billing, $user);

        $billing->restore();

        $this->auditLogService->log(
            'billing.restore',
            $billing,
            null,
            $billing->fresh()->toArray(),
            ['message' => 'Billing restored'],
            $billing->store_id
        );

        return $billing->fresh()->load(['customer', 'store', 'user', 'items.product', 'payments']);
    }
}