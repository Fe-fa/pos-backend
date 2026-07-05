<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashierShiftService
{
    /* -----------------------------------------------------------------
       PUBLIC API
       ----------------------------------------------------------------- */

    public function ensureShiftTableExists(): void
    {
        abort_unless(
            Schema::hasTable('cashier_shifts'),
            422,
            'Cashier shifts are not ready yet. Please run the cashier shift migration first.'
        );
    }

    public function requireOpenShift(User $cashier, int $storeId, ?string $businessDate = null): CashierShift
    {
        $this->ensureShiftTableExists();
        $this->assertStoreAccess($cashier, $storeId);

        $resolvedDate = $this->resolveBusinessDate($businessDate);

        $shift = CashierShift::query()
            ->where('store_id', $storeId)
            ->where('user_id', $cashier->user_id)
            ->whereDate('business_date', $resolvedDate)
            ->where('status', 'open')
            ->first();

        abort_unless(
            $shift,
            422,
            'Open your cash shift and record the opening balance before processing sales.'
        );

        return $shift;
    }

    public function getTodayForUser(User $cashier, int $storeId, ?string $businessDate = null): array
    {
        $resolvedDate = $this->resolveBusinessDate($businessDate);

        if (!Schema::hasTable('cashier_shifts')) {
            return [
                'shift'   => null,
                'summary' => $this->emptyUserSummary($storeId, (int) $cashier->user_id, $resolvedDate),
            ];
        }

        $this->assertStoreAccess($cashier, $storeId);

        $shift = CashierShift::query()
            ->with(['cashier:user_id,first_name,last_name,email', 'store:store_id,store_name,currency'])
            ->where('store_id', $storeId)
            ->where('user_id', $cashier->user_id)
            ->whereDate('business_date', $resolvedDate)
            ->first();

        $safeSummary = $shift
            ? [
                'cashier_shift_id'    => (int) $shift->cashier_shift_id,
                'business_date'       => $resolvedDate,
                'store_id'            => $storeId,
                'store_name'          => $shift->store?->store_name ?? 'Store',
                'currency'            => $shift->store?->currency ?? 'KES',
                'cashier_user_id'     => (int) $cashier->user_id,
                'cashier_name'        => trim(($shift->cashier?->first_name ?? '') . ' ' . ($shift->cashier?->last_name ?? '')) ?: 'Cashier',
                'status'              => $shift->status,
                'opening_balance'     => round((float) $shift->opening_balance, 2),
                'carry_forward_variance' => round((float) ($shift->carry_forward_variance ?? 0), 2),
                'opened_at'           => optional($shift->opened_at)?->toISOString(),
                'closed_at'           => optional($shift->closed_at)?->toISOString(),
            ]
            : $this->emptyUserSummary($storeId, (int) $cashier->user_id, $resolvedDate);

        return [
            'shift'   => $shift ? $this->transformShift($shift) : null,
            'summary' => $safeSummary,
        ];
    }

    public function openShift(User $cashier, int $storeId, float $openingBalance, ?string $openingNote = null, ?string $businessDate = null): array
    {
        $this->ensureShiftTableExists();
        $this->assertStoreAccess($cashier, $storeId);

        $resolvedDate = $this->resolveBusinessDate($businessDate);

        $existing = CashierShift::query()
            ->where('store_id', $storeId)
            ->where('user_id', $cashier->user_id)
            ->whereDate('business_date', $resolvedDate)
            ->first();

        if ($existing) {
            abort_if(
                $existing->status === 'closed',
                422,
                'This cashier shift has already been closed for the selected business date.'
            );

            return [
                'shift'   => $this->transformShift($existing->loadMissing(['cashier:user_id,first_name,last_name,email', 'store:store_id,store_name,currency'])),
                'summary' => $this->buildUserDaySummary($storeId, (int) $cashier->user_id, $resolvedDate),
            ];
        }

        $carryVariance   = $this->getPreviousShiftVariance($storeId, (int) $cashier->user_id, $resolvedDate);
        $adjustedOpening = round($openingBalance + $carryVariance, 2);
        $adjustedOpening = max($adjustedOpening, 0);

        $shift = CashierShift::query()->create([
            'store_id'               => $storeId,
            'user_id'                => (int) $cashier->user_id,
            'business_date'          => $resolvedDate,
            'status'                 => 'open',
            'opening_balance'        => $adjustedOpening,
            'opening_note'           => $openingNote,
            'carry_forward_variance' => $carryVariance,
            'opened_at'              => now(),
        ]);

        $shift->load(['cashier:user_id,first_name,last_name,email', 'store:store_id,store_name,currency']);

        return [
            'shift'   => $this->transformShift($shift),
            'summary' => $this->buildUserDaySummary($storeId, (int) $cashier->user_id, $resolvedDate),
        ];
    }

    public function closeShift(User $actor, int $storeId, ?int $cashierUserId = null, ?float $countedCash = null, ?string $closeNote = null, ?string $businessDate = null): array
    {
        $this->ensureShiftTableExists();
        $this->assertStoreAccess($actor, $storeId);

        $resolvedDate     = $this->resolveBusinessDate($businessDate);
        $targetCashierId  = $cashierUserId ?: (int) $actor->user_id;
        $isSelf           = (int) $actor->user_id === $targetCashierId;
        $isManagerOrAdmin = $actor->isAdmin() || $actor->isManager();

        if (!$isSelf && !$isManagerOrAdmin) {
            abort(403, 'You are not allowed to close another cashier\'s shift. Only a manager or admin can do that.');
        }

        $shift = CashierShift::query()
            ->where('store_id', $storeId)
            ->where('user_id', $targetCashierId)
            ->whereDate('business_date', $resolvedDate)
            ->where('status', 'open')
            ->first();

        abort_unless($shift, 404, 'No open cashier shift was found for that cashier and business date.');

        $summary        = $this->buildUserDaySummary($storeId, $targetCashierId, $resolvedDate);
        $openingBalance = round((float) ($shift->opening_balance ?? 0), 2);
        $cashSales      = round((float) ($summary['cash_sales'] ?? 0), 2);
        $expectedCash   = round($openingBalance + $cashSales, 2);
        $counted        = $countedCash !== null ? round($countedCash, 2) : null;
        $variance       = $counted !== null ? round($counted - $expectedCash, 2) : null;


        abort_if($counted === null, 422, 'Physical drawer count is required before closing a shift.');

        $pendingOrders = DB::table('billing')
            ->where('store_id', $storeId)
            ->where('user_id', $targetCashierId)
            ->whereNull('deleted_at')
            ->whereDate('billing_date', $resolvedDate)
            ->where(function ($query) {
                $query->where('is_draft', 1)
                    ->orWhereIn('status', ['draft', 'parked', 'quote', 'pending']);
            })
            ->count();

        $openVoids = DB::table('billing')
            ->where('store_id', $storeId)
            ->where('user_id', $targetCashierId)
            ->whereNull('deleted_at')
            ->whereDate('billing_date', $resolvedDate)
            ->whereIn('status', ['void', 'voided', 'cancelled', 'canceled'])
            ->count();

        if ($pendingOrders > 0 || $openVoids > 0) {
            $parts = [];
            if ($openVoids > 0) {
                $parts[] = "{$openVoids} void(s)";
            }
            if ($pendingOrders > 0) {
                $parts[] = "{$pendingOrders} draft/pending sale(s)";
            }

            abort(422, 'Clear unresolved cashier items before closing this shift: ' . implode(' and ', $parts) . '.');
        }

        $shift->update([
            'status'                 => 'closed',
            'closed_at'              => now(),
            'closed_by_user_id'      => (int) $actor->user_id,
            'counted_cash'           => $counted,
            'expected_cash'          => $expectedCash,
            'variance'               => $variance,
            'carry_forward_variance' => $variance ?? 0,
            'close_note'             => $closeNote,
            'summary_snapshot'       => $summary,
        ]);

        $shift->load(['cashier:user_id,first_name,last_name,email', 'store:store_id,store_name,currency', 'closedBy:user_id,first_name,last_name']);

        return [
            'shift'   => $this->transformShift($shift),
            'summary' => $summary,
        ];
    }

    public function getCashierDailySales(User $actor, int $storeId, int $cashierUserId, ?string $businessDate = null): array
    {
        $this->ensureShiftTableExists();
        $this->assertStoreAccess($actor, $storeId);

        $isSelf           = (int) $actor->user_id === $cashierUserId;
        $isManagerOrAdmin = $actor->isAdmin() || $actor->isManager();

        if (!$isSelf && !$isManagerOrAdmin) {
            abort(403, 'You can only view your own daily sales report.');
        }

        return $this->buildUserDaySummary($storeId, $cashierUserId, $businessDate);
    }

    public function buildScopedDailySummary(array $storeIds, ?string $businessDate = null): array
    {
        $resolvedDate = $this->resolveBusinessDate($businessDate);

        $normalizedStoreIds = collect($storeIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($normalizedStoreIds) || !Schema::hasTable('cashier_shifts')) {
            return $this->emptyScopedSummary($resolvedDate);
        }

        $shiftRows = CashierShift::query()
            ->with(['cashier:user_id,first_name,last_name,email', 'store:store_id,store_name,currency'])
            ->whereIn('store_id', $normalizedStoreIds)
            ->whereDate('business_date', $resolvedDate)
            ->orderBy('store_id')
            ->orderBy('user_id')
            ->get();

        $rows = $shiftRows
            ->map(function (CashierShift $shift) use ($resolvedDate) {
                return $this->buildUserDaySummary((int) $shift->store_id, (int) $shift->user_id, $resolvedDate);
            })
            ->values();

        $productsSold = $this->buildProductsSoldSummary($normalizedStoreIds, null, $resolvedDate);

        return [
            'business_date'           => $resolvedDate,
            'rows'                    => $rows->all(),
            'products_sold'           => $productsSold,
            'total_items_sold'        => (int) collect($productsSold)->sum('qty'),
            'total_opening_balance'   => round((float) $rows->sum('opening_balance'), 2),
            'total_sales'             => round((float) $rows->sum('total_sales'), 2),
            'total_cash_sales'        => round((float) $rows->sum('cash_sales'), 2),
            'total_non_cash_sales'    => round((float) $rows->sum('non_cash_sales'), 2),
            'total_refunds'           => round((float) $rows->sum('refunds'), 2),
            'total_transactions'      => (int) $rows->sum('transactions'),
            'total_expected_cash'     => round((float) $rows->sum('expected_cash'), 2),
            'total_counted_cash'      => round((float) $rows->filter(fn ($r) => $r['counted_cash'] !== null)->sum('counted_cash'), 2),
            'total_variance'          => round((float) $rows->filter(fn ($r) => $r['variance'] !== null)->sum('variance'), 2),
            'total_pending_orders'    => (int) $rows->sum('pending_orders'),
            'total_carry_forward'     => round((float) $rows->sum('carry_forward_variance'), 2),
            'open_shift_count'        => (int) $rows->where('status', 'open')->count(),
            'closed_shift_count'      => (int) $rows->where('status', 'closed')->count(),
        ];
    }

    public function getPreviousShiftVariance(int $storeId, int $cashierUserId, string $businessDate): float
    {
        if (!Schema::hasTable('cashier_shifts')) {
            return 0.0;
        }

        $previousShift = CashierShift::query()
            ->where('store_id', $storeId)
            ->where('user_id', $cashierUserId)
            ->where('status', 'closed')
            ->whereDate('business_date', '<', $businessDate)
            ->orderByDesc('business_date')
            ->first();

        if (!$previousShift || $previousShift->variance === null) {
            return 0.0;
        }

        return round((float) $previousShift->variance, 2);
    }

    public function buildUserDaySummary(int $storeId, int $cashierUserId, ?string $businessDate = null): array
    {
        $resolvedDate = $this->resolveBusinessDate($businessDate);

        if (!Schema::hasTable('billing')) {
            return $this->emptyUserSummary($storeId, $cashierUserId, $resolvedDate);
        }

        $store = DB::table('stores')
            ->select('store_id', 'store_name', 'currency')
            ->where('store_id', $storeId)
            ->first();

        $cashier = DB::table('users')
            ->selectRaw("user_id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS cashier_name")
            ->where('user_id', $cashierUserId)
            ->first();

        $shiftRow = Schema::hasTable('cashier_shifts')
            ? DB::table('cashier_shifts')
                ->select(
                    'cashier_shift_id',
                    'status',
                    'opening_balance',
                    'opening_note',
                    'counted_cash',
                    'expected_cash',
                    'variance',
                    'carry_forward_variance',
                    'close_note',
                    'opened_at',
                    'closed_at',
                    'closed_by_user_id'
                )
                ->where('store_id', $storeId)
                ->where('user_id', $cashierUserId)
                ->whereDate('business_date', $resolvedDate)
                ->first()
            : null;

        $billingAgg = DB::table('billing as b')
            ->selectRaw("
                COUNT(CASE WHEN b.`status` IN ('paid','partial') AND b.is_draft = 0 THEN 1 END) AS transactions,
                SUM(CASE WHEN b.`status` IN ('paid','partial') AND b.is_draft = 0 THEN b.paid_amount ELSE 0 END) AS total_sales,
                SUM(CASE WHEN b.balance_due > 0 AND b.is_draft = 0 THEN b.balance_due ELSE 0 END) AS outstanding,
                COUNT(CASE WHEN b.`status` IN ('void','voided','cancelled','canceled') THEN 1 END) AS total_voids,
                COUNT(CASE WHEN b.is_draft = 1 OR b.`status` IN ('draft','parked','quote','pending') THEN 1 END) AS pending_orders,
                SUM(CASE WHEN b.`status` IN ('refund','refunded','returned','return') THEN b.`total` ELSE 0 END) AS refunds
            ")
            ->where('b.store_id', $storeId)
            ->where('b.user_id', $cashierUserId)
            ->whereNull('b.deleted_at')
            ->whereDate('b.billing_date', $resolvedDate)
            ->first();

        $paymentBreakdown = Schema::hasTable('payments')
            ? DB::table('payments as p')
                ->join('billing as b', 'b.billing_id', '=', 'p.billing_id')
                ->selectRaw("
                    COALESCE(NULLIF(TRIM(p.payment_method), ''), 'Unknown') AS method,
                    COUNT(*) AS count,
                    SUM(p.amount_received) AS amount
                ")
                ->where('b.store_id', $storeId)
                ->where('b.user_id', $cashierUserId)
                ->whereNull('b.deleted_at')
                ->whereDate('b.billing_date', $resolvedDate)
                ->whereIn('b.status', ['paid', 'partial'])
                ->where('b.is_draft', 0)
                ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(p.payment_method), ''), 'Unknown')"))
                ->orderByDesc('amount')
                ->get()
                ->map(fn ($row) => [
                    'method' => $row->method,
                    'count'  => (int) $row->count,
                    'amount' => round((float) $row->amount, 2),
                ])
                ->values()
                ->all()
            : [];

        $productsSold = $this->buildProductsSoldSummary([$storeId], $cashierUserId, $resolvedDate);

        $cashSales      = collect($paymentBreakdown)
            ->filter(fn ($row) => strtolower((string) ($row['method'] ?? '')) === 'cash')
            ->sum('amount');

        $totalSales       = round((float) ($billingAgg->total_sales ?? 0), 2);
        $openingBalance   = round((float) ($shiftRow->opening_balance ?? 0), 2);
        $carryForward     = round((float) ($shiftRow->carry_forward_variance ?? 0), 2);
        $expectedCash     = round((float) ($shiftRow->expected_cash ?? ($openingBalance + $cashSales)), 2);

        $closedByName = null;
        if ($shiftRow && $shiftRow->closed_by_user_id) {
            $closedByUser = DB::table('users')
                ->selectRaw("TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS full_name")
                ->where('user_id', $shiftRow->closed_by_user_id)
                ->first();
            $closedByName = $closedByUser?->full_name ?: 'Unknown';
        }

        return [
            'cashier_shift_id'        => $shiftRow->cashier_shift_id ?? null,
            'business_date'           => $resolvedDate,
            'store_id'                => $storeId,
            'store_name'              => $store->store_name ?? 'Store',
            'currency'                => $store->currency ?? 'KES',
            'cashier_user_id'         => $cashierUserId,
            'cashier_name'            => trim((string) ($cashier->cashier_name ?? '')) ?: 'Cashier',
            'status'                  => $shiftRow->status ?? null,
            'opening_balance'         => $openingBalance,
            'carry_forward_variance'  => $carryForward,
            'opening_note'            => $shiftRow->opening_note ?? null,
            'transactions'            => (int) ($billingAgg->transactions ?? 0),
            'total_sales'             => $totalSales,
            'cash_sales'              => round((float) $cashSales, 2),
            'non_cash_sales'          => round(max($totalSales - $cashSales, 0), 2),
            'refunds'                 => round((float) ($billingAgg->refunds ?? 0), 2),
            'outstanding'             => round((float) ($billingAgg->outstanding ?? 0), 2),
            'total_voids'             => (int) ($billingAgg->total_voids ?? 0),
            'pending_orders'          => (int) ($billingAgg->pending_orders ?? 0),
            'expected_cash'           => $expectedCash,
            'counted_cash'            => $shiftRow?->counted_cash !== null ? round((float) $shiftRow->counted_cash, 2) : null,
            'variance'                => $shiftRow?->variance !== null ? round((float) $shiftRow->variance, 2) : null,
            'close_note'              => $shiftRow->close_note ?? null,
            'closed_by_name'          => $closedByName,
            'opened_at'               => $shiftRow?->opened_at ? Carbon::parse($shiftRow->opened_at)->toISOString() : null,
            'closed_at'               => $shiftRow?->closed_at ? Carbon::parse($shiftRow->closed_at)->toISOString() : null,
            'payment_breakdown'       => $paymentBreakdown,
            'products_sold'           => $productsSold,
            'total_items_sold'        => (int) collect($productsSold)->sum('qty'),
        ];
    }

    /* -----------------------------------------------------------------
       PRODUCT SUMMARY
       ----------------------------------------------------------------- */
private function buildProductsSoldSummary(array $storeIds, ?int $cashierUserId, string $businessDate): array
{
    if (!Schema::hasTable('billing_items') || !Schema::hasTable('products')) {
        return [];
    }

    $productNameExpr = "COALESCE(NULLIF(TRIM(p.product_name), ''), CONCAT('Product #', bi.product_id))";

    $query = DB::table('billing_items as bi')
        ->join('billing as b', 'b.billing_id', '=', 'bi.billing_id')
        ->leftJoin('products as p', 'p.product_id', '=', 'bi.product_id')
        ->selectRaw("
            bi.product_id,
            {$productNameExpr} AS product_name,
            SUM(COALESCE(bi.quantity, 0)) AS qty,
            SUM(COALESCE(bi.total_amount, COALESCE(bi.quantity, 0) * COALESCE(bi.unit_price, 0))) AS amount
        ")
        ->whereIn('b.store_id', $storeIds)
        ->whereNull('b.deleted_at')
        ->whereDate('b.billing_date', $businessDate)
        ->whereIn('b.status', ['paid', 'partial'])
        ->where('b.is_draft', 0);

    if ($cashierUserId !== null) {
        $query->where('b.user_id', $cashierUserId);
    }

    return $query
        ->groupBy('bi.product_id', DB::raw($productNameExpr))
        ->orderByDesc('qty')
        ->orderByDesc('amount')
        ->get()
        ->map(fn ($row) => [
            'product_id'   => (int) $row->product_id,
            'product_name' => $row->product_name,
            'qty'          => (int) round((float) $row->qty),
            'amount'       => round((float) $row->amount, 2),
        ])
        ->values()
        ->all();
}
    /* -----------------------------------------------------------------
       PRIVATE HELPERS
       ----------------------------------------------------------------- */

    private function emptyUserSummary(int $storeId, int $cashierUserId, string $businessDate): array
    {
        return [
            'cashier_shift_id'        => null,
            'business_date'           => $businessDate,
            'store_id'                => $storeId,
            'store_name'              => 'Store',
            'currency'                => 'KES',
            'cashier_user_id'         => $cashierUserId,
            'cashier_name'            => 'Cashier',
            'status'                  => null,
            'opening_balance'         => 0,
            'carry_forward_variance'  => 0,
            'opening_note'            => null,
            'transactions'            => 0,
            'total_sales'             => 0,
            'cash_sales'              => 0,
            'non_cash_sales'          => 0,
            'refunds'                 => 0,
            'outstanding'             => 0,
            'total_voids'             => 0,
            'pending_orders'          => 0,
            'expected_cash'           => 0,
            'counted_cash'            => null,
            'variance'                => null,
            'close_note'              => null,
            'closed_by_name'          => null,
            'opened_at'               => null,
            'closed_at'               => null,
            'payment_breakdown'       => [],
            'products_sold'           => [],
            'total_items_sold'        => 0,
        ];
    }

    private function emptyScopedSummary(string $businessDate): array
    {
        return [
            'business_date'           => $businessDate,
            'rows'                    => [],
            'products_sold'           => [],
            'total_items_sold'        => 0,
            'total_opening_balance'   => 0,
            'total_sales'             => 0,
            'total_cash_sales'        => 0,
            'total_non_cash_sales'    => 0,
            'total_refunds'           => 0,
            'total_transactions'      => 0,
            'total_expected_cash'     => 0,
            'total_counted_cash'      => 0,
            'total_variance'          => 0,
            'total_pending_orders'    => 0,
            'total_carry_forward'     => 0,
            'open_shift_count'        => 0,
            'closed_shift_count'      => 0,
        ];
    }

    private function transformShift(CashierShift $shift): array
    {
        $closedByName = null;
        if ($shift->closedBy) {
            $closedByName = trim(($shift->closedBy->first_name ?? '') . ' ' . ($shift->closedBy->last_name ?? '')) ?: 'Unknown';
        }

        return [
            'cashier_shift_id'        => (int) $shift->cashier_shift_id,
            'store_id'                => (int) $shift->store_id,
            'user_id'                 => (int) $shift->user_id,
            'business_date'           => optional($shift->business_date)?->format('Y-m-d'),
            'status'                  => $shift->status,
            'opening_balance'         => round((float) $shift->opening_balance, 2),
            'opening_note'            => $shift->opening_note,
            'counted_cash'            => $shift->counted_cash !== null ? round((float) $shift->counted_cash, 2) : null,
            'expected_cash'           => $shift->expected_cash !== null ? round((float) $shift->expected_cash, 2) : null,
            'variance'                => $shift->variance !== null ? round((float) $shift->variance, 2) : null,
            'carry_forward_variance'  => round((float) ($shift->carry_forward_variance ?? 0), 2),
            'close_note'              => $shift->close_note,
            'opened_at'               => optional($shift->opened_at)?->toISOString(),
            'closed_at'               => optional($shift->closed_at)?->toISOString(),
            'closed_by_name'          => $closedByName,
            'cashier'                 => [
                'user_id' => (int) ($shift->cashier?->user_id ?? $shift->user_id),
                'name'    => trim(($shift->cashier?->first_name ?? '') . ' ' . ($shift->cashier?->last_name ?? '')) ?: 'Cashier',
                'email'   => $shift->cashier?->email,
            ],
            'store'                   => [
                'store_id'   => (int) ($shift->store?->store_id ?? $shift->store_id),
                'store_name' => $shift->store?->store_name ?? 'Store',
                'currency'   => $shift->store?->currency ?? 'KES',
            ],
        ];
    }

    private function assertStoreAccess(User $user, int $storeId): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $allowedIds = $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        abort_unless(in_array($storeId, $allowedIds, true), 403, 'You do not have access to this store.');
    }

    private function resolveBusinessDate(?string $businessDate = null): string
    {
        return $businessDate
            ? Carbon::parse($businessDate)->toDateString()
            : now()->toDateString();
    }
}
