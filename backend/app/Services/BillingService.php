<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
        private readonly InventoryService $inventoryService,
    ) {}

    public function allowedStoreIds(User $user): Collection
    {
        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

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

public function applyListFilters(Builder $query, array $filters): Builder
{
    // Store filter
    if (!empty($filters['store_id'])) {
        $query->where('store_id', (int) $filters['store_id']);
    }

    // Customer filter
    if (!empty($filters['customer_id'])) {
        $query->where('customer_id', (int) $filters['customer_id']);
    }

    // ✅ Cashier / operator filter
    if (!empty($filters['user_id'])) {
        $query->where('user_id', (int) $filters['user_id']);
    }

    // ✅ Payment method filter
    if (!empty($filters['payment_method'])) {
        $query->whereHas('payments', function (Builder $q) use ($filters) {
            $q->where('payment_method', $filters['payment_method']);
        });
    }

    // ✅ Date range filters
    if (!empty($filters['date_from'])) {
        $query->whereDate('billing_date', '>=', $filters['date_from']);
    }

    if (!empty($filters['date_to'])) {
        $query->whereDate('billing_date', '<=', $filters['date_to']);
    }

    // Status
    if (!empty($filters['status'])) {
        $statuses = array_filter(array_map('trim', explode(',', $filters['status'])));
        count($statuses) === 1
            ? $query->where('status', $statuses[0])
            : $query->whereIn('status', $statuses);
    }

    // Draft flag
    if (isset($filters['is_draft']) && $filters['is_draft'] !== '') {
        $query->where('is_draft', filter_var($filters['is_draft'], FILTER_VALIDATE_BOOLEAN));
    }

    // Fulfillment filters
    if (!empty($filters['fulfillment_status'])) {
        $query->where('fulfillment_status', $filters['fulfillment_status']);
    }

    if (!empty($filters['fulfillment_type'])) {
        $query->where('fulfillment_type', $filters['fulfillment_type']);
    }

    // Soft-delete scope
    if (!empty($filters['only_trashed'])) {
        $query->onlyTrashed();
    } elseif (!empty($filters['with_trashed'])) {
        $query->withTrashed();
    }
if (!empty($filters['invnumber'])) {
    $term = $filters['invnumber'];
    $query->where(function (Builder $q) use ($term) {
        // Match invoice number OR receipt number on any payment
        $q->where('invnumber', $term)
          ->orWhereHas('payments', function (Builder $p) use ($term) {
              $p->where('receiptnumber', $term);
          });
    });
}

if (!empty($filters['search'])) {
    $term = $filters['search'];
    $query->where(function (Builder $q) use ($term) {
        // Match invoice number, receipt number, customer phone or customer name
        $q->where('invnumber', $term)
          ->orWhereHas('payments', function (Builder $p) use ($term) {
              $p->where('receiptnumber', $term);
          })
          ->orWhereHas('customer', function (Builder $c) use ($term) {
              $c->where('phone', $term)
                ->orWhere('full_name', $term);
          });
    });
}

    return $query;
}

    public function authorizeStoreAccess(User $user, int|string|null $storeId): void
    {
        if (!$storeId || $user->isAdmin()) {
            return;
        }

        $allowed = $this->allowedStoreIds($user)->all();

        if (!in_array((int) $storeId, $allowed, true)) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'You are not allowed to access this store.',
                ], 403)
            );
        }
    }

public function authorizeBillingAccess(Billing $billing, ?User $actor = null, bool $viewOnly = false): void
{
    $actor = $actor ?: auth()->user();

    if (!$actor) {
        throw new HttpResponseException(
            response()->json(['message' => 'Unauthenticated.'], 401)
        );
    }

    if ($actor->isAdmin()) {
        return;
    }

    $storeIds = $this->allowedStoreIds($actor)->all();
    $hasStoreAccess = in_array((int) $billing->store_id, $storeIds, true);

    if ($actor->isManager()) {
        if (!$hasStoreAccess) {
            throw new HttpResponseException(
                response()->json(['message' => 'You are not allowed to access this billing.'], 403)
            );
        }
        return;
    }

    // Cashier — must have store access
    if (!$hasStoreAccess) {
        throw new HttpResponseException(
            response()->json(['message' => 'You are not allowed to access this billing.'], 403)
        );
    }

    // View-only (reprint) — store access is enough, no ownership check
    if ($viewOnly) {
        return;
    }

    // Manage actions — cashier must own the billing
    $ownsBilling = (string) $billing->user_id === (string) $actor->user_id;

    if (!$ownsBilling) {
        throw new HttpResponseException(
            response()->json(['message' => 'You are not allowed to access this billing.'], 403)
        );
    }
}

    public function createDraft(User $user, array $data): Billing
    {
        $this->authorizeStoreAccess($user, $data['store_id']);

        $billing = Billing::create([
            'store_id'           => $data['store_id'],
            'customer_id'        => $data['customer_id'] ?? null,
            'user_id'            => $user->user_id,
            'invnumber'          => null,
            'status'             => 'unpaid',
            'subtotal'           => 0,
            'vat_amount'         => 0,
            'total'              => 0,
            'paid_amount'        => 0,
            'balance_due'        => 0,
            'is_draft'           => true,
            'billing_date'       => now(),
            'notes'              => $data['notes'] ?? null,
            'fulfillment_status' => $data['fulfillment_status'] ?? 'pending',
            'fulfillment_type'   => $data['fulfillment_type'] ?? 'walk_in_counter',
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
        $this->authorizeBillingAccess($billing, viewOnly: true);

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
       $billing->load(['items' => fn($q) => $q->whereNull('deleted_at')]);

        $subtotal       = (float) $billing->items->sum('line_subtotal');
        $vatAmount      = (float) $billing->items->sum('vat_amount');
        $grossTotal     = $subtotal + $vatAmount;
        $pointsDiscount = (float) ($billing->points_discount ?? 0);
        $total          = max($grossTotal - $pointsDiscount, 0);

        $paidAmount = (float) $billing->payments()->sum('amount_received');
        $balanceDue = max($total - $paidAmount, 0);

        $billing->update([
            'subtotal'    => $subtotal,
            'vat_amount'  => $vatAmount,
            'total'       => $total,
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
            $billing->load(['items' => fn($q) => $q->whereNull('deleted_at'), 'items.product', 'payments']);

            if ($billing->items->isEmpty()) {
                throw new HttpResponseException(
                    response()->json([
                        'message' => 'Cannot finalize billing without items.',
                    ], 422)
                );
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
                'is_draft'         => false,
                'status'           => ((float) $billing->balance_due <= 0) ? 'paid' : 'unpaid',
                'invnumber'        => $invoiceNumber,
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
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Only draft billings can be deleted from cashier POS.',
                ], 422)
            );
        }

        if ($billing->payments()->exists()) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Cannot delete billing with payments.',
                ], 422)
            );
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
            throw new HttpResponseException(
                response()->json([
                    'message' => "Billing record #{$billingId} was not found in trash.",
                ], 404)
            );
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