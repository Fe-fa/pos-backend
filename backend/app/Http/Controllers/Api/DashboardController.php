<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function superAdmin(Request $request): JsonResponse
    {
        $user = $request->user();

        // ── 1. Stores this user may see ───────────────────────────────────
        $storeQuery = Store::query();

        if (! $user->isAdmin()) {
            $allowedIds = $user->stores()
                ->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $storeQuery->whereIn('store_id', $allowedIds);
        }

        // Only select columns that actually exist in the stores table
        $stores = $storeQuery->select([
            'store_id',
            'store_name',
            'location',
            'physical_address',
            'currency',
            'is_active',
            'created_at',
            'updated_at',
        ])->get();

        $storeIds    = $stores->pluck('store_id')->all();
        $totalStores = $stores->count();

        if (empty($storeIds)) {
            return response()->json($this->emptyPayload());
        }

        // ── 2. Tenant metrics ─────────────────────────────────────────────
        // Since the stores table has no subscription/status columns, we
        // derive "active" from is_active (boolean) which does exist.
        $activeTenants = $stores->where('is_active', true);

        $now       = now();
        $thirtyAgo = $now->copy()->subDays(30);
        $sixtyAgo  = $now->copy()->subDays(60);

        $newTenants30 = $stores->filter(
            fn ($s) => $this->inRange($s->created_at, $thirtyAgo, $now)
        )->count();

        $prevTenants30 = $stores->filter(
            fn ($s) => $this->inRange($s->created_at, $sixtyAgo, $thirtyAgo)
        )->count();

        // No cancelled_at column exists — churn derived from is_active = false
        // updated within the last 30 days
        $churnedTenants30 = $stores->filter(
            fn ($s) => ! $s->is_active && $this->inRange($s->updated_at, $thirtyAgo, $now)
        )->count();

        // No subscription_amount column — MRR is 0 unless you add that column later
        $mrr = 0.0;

        $activeTenantCount = $activeTenants->count();

        $signupRate = $prevTenants30 > 0
            ? (($newTenants30 - $prevTenants30) / $prevTenants30) * 100
            : ($newTenants30 > 0 ? 100.0 : 0.0);

        $churnBase = $activeTenantCount + $churnedTenants30;
        $churnRate = $churnBase > 0
            ? ($churnedTenants30 / $churnBase) * 100
            : 0.0;

        // ── 3. Simple counts ──────────────────────────────────────────────
        $productCount  = Product::whereIn('store_id', $storeIds)->count();
        $customerCount = Customer::whereIn('store_id', $storeIds)->count();
        $staffCount    = User::where('role', '!=', 'admin')
            ->where(function ($q) use ($storeIds) {
                $q->whereIn('default_store_id', $storeIds)
                  ->orWhereHas('stores', fn ($r) => $r->whereIn('stores.store_id', $storeIds));
            })->count();

        // ── 4. Billing aggregates — one query ─────────────────────────────
        // Table confirmed as 'billing' from the Billing model ($table = 'billing')
        $today    = now()->toDateString();
        $sevenAgo = now()->subDays(6)->toDateString();

        $billingAgg = DB::table('billing')
            ->select([
                'store_id',
                DB::raw("SUM(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN `total` ELSE 0 END) AS gross_billed"),
                DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS paid_collections"),
                DB::raw("SUM(CASE WHEN is_draft = 0 THEN balance_due ELSE 0 END) AS outstanding_total"),
                DB::raw("COUNT(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN 1 END) AS total_orders"),
                DB::raw("COUNT(CASE WHEN balance_due > 0 AND is_draft = 0 THEN 1 END) AS open_balances_count"),
                // Today
                DB::raw("SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS today_collected"),
                DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` != 'draft' AND is_draft = 0 THEN 1 END) AS today_orders"),
                DB::raw("SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('refund','refunded') THEN `total` ELSE 0 END) AS today_refund_value"),
                DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('refund','refunded') THEN 1 END) AS today_refund_count"),
                DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('void','voided','cancelled','canceled','draft') THEN 1 END) AS today_voids"),
                DB::raw("SUM(CASE WHEN DATE(billing_date) = '{$today}' AND is_draft = 0 THEN balance_due ELSE 0 END) AS today_outstanding"),
            ])
            ->whereIn('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $grossBilled       = (float) $billingAgg->sum('gross_billed');
        $paidCollections   = (float) $billingAgg->sum('paid_collections');
        $outstandingTotal  = (float) $billingAgg->sum('outstanding_total');
        $totalOrders       = (int)   $billingAgg->sum('total_orders');
        $openBalancesCount = (int)   $billingAgg->sum('open_balances_count');
        $todayCollected    = (float) $billingAgg->sum('today_collected');
        $todayOrders       = (int)   $billingAgg->sum('today_orders');
        $todayRefundValue  = (float) $billingAgg->sum('today_refund_value');
        $todayRefundCount  = (int)   $billingAgg->sum('today_refund_count');
        $todayVoids        = (int)   $billingAgg->sum('today_voids');
        $todayOutstanding  = (float) $billingAgg->sum('today_outstanding');

        $averageTicket         = $totalOrders > 0 ? $paidCollections / $totalOrders : 0.0;
        $collectionRate        = $grossBilled  > 0 ? ($paidCollections / $grossBilled) * 100 : 0.0;
        $avgOrdersPerTenant    = $activeTenantCount > 0 ? $totalOrders    / $activeTenantCount : 0.0;
        $avgCustomersPerTenant = $activeTenantCount > 0 ? $customerCount  / $activeTenantCount : 0.0;
        $avgRevenuePerTenant   = $activeTenantCount > 0 ? $paidCollections / $activeTenantCount : 0.0;

        // ── 5. Last-7-days series ─────────────────────────────────────────
        $last7Raw = DB::table('billing')
            ->select([
                DB::raw('DATE(billing_date) AS day'),
                DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS collected"),
                DB::raw("SUM(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN `total` ELSE 0 END) AS billed"),
                DB::raw("SUM(CASE WHEN is_draft = 0 THEN balance_due ELSE 0 END) AS outstanding"),
                DB::raw("SUM(CASE WHEN `status` IN ('refund','refunded') THEN `total` ELSE 0 END) AS refunds"),
            ])
            ->whereIn('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw('DATE(billing_date)'), [$sevenAgo, $today])
            ->groupBy(DB::raw('DATE(billing_date)'))
            ->get()
            ->keyBy('day');

        $last7Days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date      = now()->subDays($i);
            $key       = $date->toDateString();
            $row       = $last7Raw->get($key);
            $collected = (float) ($row->collected ?? 0);
            $last7Days[] = [
                'key'         => $key,
                'label'       => $date->format('D'),
                'label_short' => $date->format('d M'),
                'collected'   => $collected,
                'billed'      => (float) ($row->billed      ?? 0),
                'outstanding' => (float) ($row->outstanding ?? 0),
                'refunds'     => (float) ($row->refunds     ?? 0),
                'amount'      => $collected,   // MiniBars uses this key
            ];
        }

        $projectedMonthly = count($last7Days) > 0
            ? (array_sum(array_column($last7Days, 'collected')) / count($last7Days)) * 30
            : 0.0;

        // ── 6. Per-store performance ──────────────────────────────────────
        $storeRevRaw = DB::table('billing')
            ->select([
                'store_id',
                DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS revenue"),
                DB::raw("COUNT(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN 1 END) AS orders"),
                DB::raw("COUNT(CASE WHEN balance_due > 0 AND is_draft = 0 THEN 1 END) AS outstanding"),
            ])
            ->whereIn('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        // Inventory table confirmed as 'inventory' from the Inventory model
        // No soft-deletes on inventory — no deleted_at column, so no whereNull
        $lowStockPerStore = DB::table('inventory')
            ->select('store_id', DB::raw('COUNT(*) AS low_count'))
            ->whereIn('store_id', $storeIds)
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $storePerformance = $stores->map(function ($store) use ($storeRevRaw, $lowStockPerStore) {
            $rev = $storeRevRaw->get($store->store_id);
            $low = $lowStockPerStore->get($store->store_id);

            return [
                'store_id'    => $store->store_id,
                'store_name'  => $store->store_name ?? 'Unnamed store',
                'location'    => $store->location ?? $store->physical_address ?? '—',
                'tier'        => 'Standard',   // no tier column in stores table
                'status'      => $store->is_active ? 'active' : 'inactive',
                'revenue'     => (float) ($rev->revenue     ?? 0),
                'orders'      => (int)   ($rev->orders      ?? 0),
                'outstanding' => (int)   ($rev->outstanding ?? 0),
                'lowStock'    => (int)   ($low->low_count   ?? 0),
            ];
        })
        ->sortByDesc('revenue')
        ->values()
        ->all();

        // ── 7. Inventory health ───────────────────────────────────────────
        // Inventory model has no SoftDeletes — no whereNull('deleted_at') needed
        $invAgg = DB::table('inventory')
            ->selectRaw('
                COUNT(*) AS total_rows,
                SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) AS low_stock_count
            ')
            ->whereIn('store_id', $storeIds)
            ->first();

        $totalInvRows      = (int) ($invAgg->total_rows      ?? 0);
        $lowStockCount     = (int) ($invAgg->low_stock_count ?? 0);
        $healthyStockCount = max($totalInvRows - $lowStockCount, 0);
        $inventoryHealth   = $totalInvRows > 0
            ? ($healthyStockCount / $totalInvRows) * 100
            : 0.0;

        // ── 8. New tenants today ──────────────────────────────────────────
        $newTenantsToday = $stores->filter(
            fn ($s) => optional($s->created_at)->toDateString() === $today
        )->count();

        // ── 9. Currency ───────────────────────────────────────────────────
        $currency = $activeTenants->first()?->currency
            ?? $stores->first()?->currency
            ?? 'KES';

        // ── 10. Response ──────────────────────────────────────────────────
        return response()->json([
            'currency' => $currency,

            'platform' => [
                'mrr'                => round($mrr, 2),
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
            'store_performance' => array_slice($storePerformance, 0, 8),
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function inRange($value, $start, $end): bool
    {
        if (! $value) return false;
        try {
            $ts = \Carbon\Carbon::parse($value);
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