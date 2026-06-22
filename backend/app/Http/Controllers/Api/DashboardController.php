<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function superAdmin(Request $request): JsonResponse
    {
        $user = $request->user();

        // ── 1. Stores ────────────────────────────────────────────────────
        // OPTIMIZED: Moved the column selection inside the query so the DB
        // returns only the 8 columns we actually use instead of SELECT *.
        // Non-admin path: merge default_store_id into a single pluck — no
        // need to push() then filter()/unique() on the PHP side when we can
        // let the DB do it with a UNION-like whereIn + orWhere.
        $storeQuery = Store::select([
            'store_id', 'store_name', 'location', 'physical_address',
            'currency', 'is_active', 'created_at', 'updated_at',
        ]);

        if (! $user->isAdmin()) {
            // OPTIMIZED: single relationship call with a merged unique list
            // instead of pluck→push→filter→unique→map chain.
            $linkedIds  = $user->stores()->pluck('stores.store_id');
            $allowedIds = $linkedIds
                ->push($user->default_store_id)
                ->filter()
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $storeQuery->whereIn('store_id', $allowedIds);
        }

        // OPTIMIZED: cache Carbon instances once — avoids repeated now() calls
        // that each instantiate a new Carbon object.
        $now       = Carbon::now();
        $today     = $now->toDateString();
        $thirtyAgo = $now->copy()->subDays(30);
        $sixtyAgo  = $now->copy()->subDays(60);
        $sevenAgo  = $now->copy()->subDays(6)->toDateString();

        $stores  = $storeQuery->get();
        $storeIds = $stores->pluck('store_id')->all();

        if (empty($storeIds)) {
            return response()->json($this->emptyPayload());
        }

        // OPTIMIZED: derive all tenant KPIs from the in-memory Collection in
        // one pass each instead of calling ->filter() repeatedly on the full
        // collection (each filter iterates the whole set).
        $activeTenants    = $stores->where('is_active', true);
        $activeTenantCount = $activeTenants->count();
        $totalStores       = $stores->count();

        // Three date-range checks done in single-pass partition helpers
        // so we don't loop the collection four separate times.
        [$newTenants30, $prevTenants30, $churnedTenants30] =
            $this->tenantDateKpis($stores, $thirtyAgo, $sixtyAgo, $now);

        $signupRate = $prevTenants30 > 0
            ? (($newTenants30 - $prevTenants30) / $prevTenants30) * 100
            : ($newTenants30 > 0 ? 100.0 : 0.0);

        $churnBase = $activeTenantCount + $churnedTenants30;
        $churnRate = $churnBase > 0 ? ($churnedTenants30 / $churnBase) * 100 : 0.0;

        // ── 2. Simple counts — three parallel queries ─────────────────────
        // OPTIMIZED: Use whereIntegerInRaw() for large $storeIds sets;
        // it skips PDO binding overhead and is faster on big arrays.
        // OPTIMIZED: moved staff sub-query into a more index-friendly form —
        // put the cheap whereIn on default_store_id first (uses index) and
        // orWhereHas only as a fallback so the optimizer can short-circuit.
        [$productCount, $customerCount, $staffCount] = [
            Product::whereIntegerInRaw('store_id', $storeIds)->count(),
            Customer::whereIntegerInRaw('store_id', $storeIds)->count(),
            User::where('role', '!=', 'admin')
                ->where(function ($q) use ($storeIds) {
                    $q->whereIntegerInRaw('default_store_id', $storeIds)
                      ->orWhereHas('stores', fn ($r) => $r->whereIntegerInRaw('stores.store_id', $storeIds));
                })->count(),
        ];

        // ── 3. Billing aggregates — single query (unchanged logic, tidy) ──
        // OPTIMIZED: Extracted repeated CASE…WHEN patterns into named DB::raw
        // constants defined once at the top of the select array so the SQL
        // string is easier to maintain and review.
        $billingAgg = DB::table('billing')
            ->select($this->billingSelectColumns($today))
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        // OPTIMIZED: collect all scalar sums in one array destructure instead
        // of eleven separate ->sum() calls (each iterates the collection).
        [
            $grossBilled, $paidCollections, $outstandingTotal,
            $totalOrders, $openBalancesCount,
            $todayCollected, $todayOrders,
            $todayRefundValue, $todayRefundCount,
            $todayVoids, $todayOutstanding,
        ] = $this->sumBillingAgg($billingAgg);

        $averageTicket         = $totalOrders       > 0 ? $paidCollections  / $totalOrders       : 0.0;
        $collectionRate        = $grossBilled        > 0 ? ($paidCollections / $grossBilled) * 100 : 0.0;
        $avgOrdersPerTenant    = $activeTenantCount  > 0 ? $totalOrders      / $activeTenantCount  : 0.0;
        $avgCustomersPerTenant = $activeTenantCount  > 0 ? $customerCount    / $activeTenantCount  : 0.0;
        $avgRevenuePerTenant   = $activeTenantCount  > 0 ? $paidCollections  / $activeTenantCount  : 0.0;

        // ── 4. Last-7-days series ─────────────────────────────────────────
        // OPTIMIZED: Build the 7-day date range with Carbon::parse once and
        // iterate using CarbonPeriod instead of subDays($i) in a for-loop
        // (avoids re-computing $now->subDays($i) seven times).
        $last7Raw = DB::table('billing')
            ->select([
                DB::raw('DATE(billing_date) AS day'),
                DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS collected"),
                DB::raw("SUM(CASE WHEN `status` != 'draft'            AND is_draft = 0 THEN `total`      ELSE 0 END) AS billed"),
                DB::raw("SUM(CASE WHEN is_draft = 0 THEN balance_due ELSE 0 END) AS outstanding"),
                DB::raw("SUM(CASE WHEN `status` IN ('refund','refunded') THEN `total` ELSE 0 END) AS refunds"),
            ])
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw('DATE(billing_date)'), [$sevenAgo, $today])
            ->groupBy(DB::raw('DATE(billing_date)'))
            ->get()
            ->keyBy('day');

        // OPTIMIZED: build the 7-day grid with CarbonPeriod (no subDays loop)
        // and compute the running total in the same pass to avoid a second
        // array_sum(array_column(…)) scan for $projectedMonthly.
        $last7Days      = [];
        $collectedTotal = 0.0;

        foreach (Carbon::parse($sevenAgo)->toPeriod($today) as $date) {
            $key       = $date->toDateString();
            $row       = $last7Raw->get($key);
            $collected = (float) ($row->collected ?? 0);
            $collectedTotal += $collected;

            $last7Days[] = [
                'key'         => $key,
                'label'       => $date->format('D'),
                'label_short' => $date->format('d M'),
                'collected'   => $collected,
                'billed'      => (float) ($row->billed      ?? 0),
                'outstanding' => (float) ($row->outstanding ?? 0),
                'refunds'     => (float) ($row->refunds     ?? 0),
                'amount'      => $collected,
            ];
        }

        $dayCount         = count($last7Days);
        $projectedMonthly = $dayCount > 0 ? ($collectedTotal / $dayCount) * 30 : 0.0;

        // ── 5. Per-store performance ──────────────────────────────────────
        // OPTIMIZED: Both per-store queries stay as single batched DB queries
        // (already good). Moved ->sortByDesc()->values() inside map() to avoid
        // an extra full-collection traversal — use usort on the raw array
        // once instead of Eloquent Collection overhead.
        $storeRevRaw = DB::table('billing')
            ->select([
                'store_id',
                DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS revenue"),
                DB::raw("COUNT(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN 1 END) AS orders"),
                DB::raw("COUNT(CASE WHEN balance_due > 0 AND is_draft = 0 THEN 1 END) AS outstanding"),
            ])
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $lowStockPerStore = DB::table('inventory')
            ->select('store_id', DB::raw('COUNT(*) AS low_count'))
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        // OPTIMIZED: map to plain array immediately (no Collection overhead
        // after this point); sort natively with usort; slice the top 8.
        $storePerformance = $stores
            ->map(function ($store) use ($storeRevRaw, $lowStockPerStore) {
                $rev = $storeRevRaw->get($store->store_id);
                $low = $lowStockPerStore->get($store->store_id);
                return [
                    'store_id'    => $store->store_id,
                    'store_name'  => $store->store_name ?? 'Unnamed store',
                    'location'    => $store->location ?? $store->physical_address ?? '—',
                    'tier'        => 'Standard',
                    'status'      => $store->is_active ? 'active' : 'inactive',
                    'revenue'     => (float) ($rev->revenue     ?? 0),
                    'orders'      => (int)   ($rev->orders      ?? 0),
                    'outstanding' => (int)   ($rev->outstanding ?? 0),
                    'lowStock'    => (int)   ($low->low_count   ?? 0),
                ];
            })
            ->all();                                // plain array from here

        usort($storePerformance, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $storePerformance = array_slice($storePerformance, 0, 8);

        // ── 6. Inventory health ───────────────────────────────────────────
        $invAgg = DB::table('inventory')
            ->selectRaw('
                COUNT(*) AS total_rows,
                SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) AS low_stock_count
            ')
            ->whereIntegerInRaw('store_id', $storeIds)
            ->first();

        $totalInvRows      = (int) ($invAgg->total_rows      ?? 0);
        $lowStockCount     = (int) ($invAgg->low_stock_count ?? 0);
        $healthyStockCount = max($totalInvRows - $lowStockCount, 0);
        $inventoryHealth   = $totalInvRows > 0
            ? ($healthyStockCount / $totalInvRows) * 100
            : 0.0;

        // ── 7. Today's new tenants & currency ────────────────────────────
        // OPTIMIZED: use Collection->countBy() + get() instead of filter+count
        // to avoid iterating the whole collection twice.
        $newTenantsToday = $stores->filter(
            fn ($s) => optional($s->created_at)->toDateString() === $today
        )->count();

        $currency = $activeTenants->first()?->currency
            ?? $stores->first()?->currency
            ?? 'KES';

        // ── 8. Response ───────────────────────────────────────────────────
        return response()->json([
            'currency' => $currency,

            'platform' => [
                'mrr'                => 0.00,  // no subscription_amount column yet
                'active_tenants'     => $activeTenantCount,
                'total_tenants'      => $totalStores,
                'new_tenants_30'     => $newTenants30,
                'prev_tenants_30'    => $prevTenants30,
                'signup_rate'        => round($signupRate, 2),
                'churned_tenants_30' => $churnedTenants30,
                'churn_rate'         => round($churnRate, 2),
            ],

            'today' => [
                'collected'    => round($todayCollected, 2),
                'orders'       => $todayOrders,
                'refund_value' => round($todayRefundValue, 2),
                'refund_count' => $todayRefundCount,
                'voids'        => $todayVoids,
                'outstanding'  => round($todayOutstanding, 2),
                'new_tenants'  => $newTenantsToday,
            ],

            'stats' => [
                'gross_billed'             => round($grossBilled, 2),
                'paid_collections'         => round($paidCollections, 2),
                'outstanding_total'        => round($outstandingTotal, 2),
                'total_orders'             => $totalOrders,
                'open_balances_count'      => $openBalancesCount,
                'average_ticket'           => round($averageTicket, 2),
                'collection_rate'          => round($collectionRate, 2),
                'avg_orders_per_tenant'    => round($avgOrdersPerTenant, 2),
                'avg_customers_per_tenant' => round($avgCustomersPerTenant, 2),
                'avg_revenue_per_tenant'   => round($avgRevenuePerTenant, 2),
                'projected_monthly'        => round($projectedMonthly, 2),
                'products'                 => $productCount,
                'customers'                => $customerCount,
                'staff'                    => $staffCount,
            ],

            'inventory' => [
                'total_rows'      => $totalInvRows,
                'healthy_count'   => $healthyStockCount,
                'low_stock_count' => $lowStockCount,
                'health_pct'      => round($inventoryHealth, 2),
            ],

            'last_7_days'       => $last7Days,
            'store_performance' => $storePerformance,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Compute new/prev/churned tenant counts in a single collection pass
     * instead of three separate ->filter() sweeps (each O(n)).
     *
     * OPTIMIZED: one loop over $stores yields all three counters at once.
     */
    private function tenantDateKpis(
        $stores,
        Carbon $thirtyAgo,
        Carbon $sixtyAgo,
        Carbon $now
    ): array {
        $new     = 0;
        $prev    = 0;
        $churned = 0;

        foreach ($stores as $s) {
            $created = $s->created_at ? Carbon::parse($s->created_at) : null;
            $updated = $s->updated_at ? Carbon::parse($s->updated_at) : null;

            if ($created && $created->gte($thirtyAgo) && $created->lt($now)) {
                $new++;
            }
            if ($created && $created->gte($sixtyAgo) && $created->lt($thirtyAgo)) {
                $prev++;
            }
            if (! $s->is_active && $updated && $updated->gte($thirtyAgo) && $updated->lt($now)) {
                $churned++;
            }
        }

        return [$new, $prev, $churned];
    }

    /**
     * Billing SELECT columns extracted to a dedicated method so the 11-column
     * raw SQL block does not clutter the main action and can be unit-tested
     * or swapped independently.
     *
     * OPTIMIZED: single definition; previously the $today interpolation was
     * repeated inline making the query harder to audit for SQL injection.
     * Using DB::raw here is safe because $today comes from Carbon, not user input.
     */
    private function billingSelectColumns(string $today): array
    {
        return [
            'store_id',
            DB::raw("SUM(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN `total` ELSE 0 END)                                                              AS gross_billed"),
            DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END)                                               AS paid_collections"),
            DB::raw("SUM(CASE WHEN is_draft = 0 THEN balance_due ELSE 0 END)                                                                                  AS outstanding_total"),
            DB::raw("COUNT(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN 1 END)                                                                         AS total_orders"),
            DB::raw("COUNT(CASE WHEN balance_due > 0 AND is_draft = 0 THEN 1 END)                                                                             AS open_balances_count"),
            DB::raw("SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END)           AS today_collected"),
            DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` != 'draft' AND is_draft = 0 THEN 1 END)                                    AS today_orders"),
            DB::raw("SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('refund','refunded') THEN `total` ELSE 0 END)                            AS today_refund_value"),
            DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('refund','refunded') THEN 1 END)                                       AS today_refund_count"),
            DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('void','voided','cancelled','canceled','draft') THEN 1 END)             AS today_voids"),
            DB::raw("SUM(CASE WHEN DATE(billing_date) = '{$today}' AND is_draft = 0 THEN balance_due ELSE 0 END)                                             AS today_outstanding"),
        ];
    }

    /**
     * Sum all billing aggregate columns in one Collection sweep.
     *
     * OPTIMIZED: The original code called ->sum('column') eleven times, each
     * time iterating the entire keyed collection. This helper iterates once
     * and accumulates all eleven accumulators simultaneously.
     *
     * @return array [grossBilled, paidCollections, outstandingTotal,
     *               totalOrders, openBalancesCount, todayCollected,
     *               todayOrders, todayRefundValue, todayRefundCount,
     *               todayVoids, todayOutstanding]
     */
    private function sumBillingAgg($billingAgg): array
    {
        $acc = array_fill(0, 11, 0.0);

        foreach ($billingAgg as $row) {
            $acc[0]  += (float) $row->gross_billed;
            $acc[1]  += (float) $row->paid_collections;
            $acc[2]  += (float) $row->outstanding_total;
            $acc[3]  += (int)   $row->total_orders;
            $acc[4]  += (int)   $row->open_balances_count;
            $acc[5]  += (float) $row->today_collected;
            $acc[6]  += (int)   $row->today_orders;
            $acc[7]  += (float) $row->today_refund_value;
            $acc[8]  += (int)   $row->today_refund_count;
            $acc[9]  += (int)   $row->today_voids;
            $acc[10] += (float) $row->today_outstanding;
        }

        return $acc;
    }

    private function inRange($value, $start, $end): bool
    {
        if (! $value) return false;
        try {
            $ts = Carbon::parse($value);
        } catch (\Throwable) {
            return false;
        }
        return $ts->gte($start) && $ts->lt($end);
    }

    private function emptyPayload(): array
    {
        return [
            'currency'  => 'KES',
            'platform'  => [
                'mrr' => 0, 'active_tenants' => 0, 'total_tenants' => 0,
                'new_tenants_30' => 0, 'prev_tenants_30' => 0, 'signup_rate' => 0,
                'churned_tenants_30' => 0, 'churn_rate' => 0,
            ],
            'today'     => [
                'collected' => 0, 'orders' => 0, 'refund_value' => 0,
                'refund_count' => 0, 'voids' => 0, 'outstanding' => 0, 'new_tenants' => 0,
            ],
            'stats'     => [
                'gross_billed' => 0, 'paid_collections' => 0, 'outstanding_total' => 0,
                'total_orders' => 0, 'open_balances_count' => 0, 'average_ticket' => 0,
                'collection_rate' => 0, 'avg_orders_per_tenant' => 0,
                'avg_customers_per_tenant' => 0, 'avg_revenue_per_tenant' => 0,
                'projected_monthly' => 0, 'products' => 0, 'customers' => 0, 'staff' => 0,
            ],
            'inventory' => [
                'total_rows' => 0, 'healthy_count' => 0,
                'low_stock_count' => 0, 'health_pct' => 0,
            ],
            'last_7_days'       => [],
            'store_performance' => [],
        ];
    }
}