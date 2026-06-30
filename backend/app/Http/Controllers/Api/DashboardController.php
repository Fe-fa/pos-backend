<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use AuthorizesPermission;

    private function resolveStoreIds(Request $request): array
    {
        $user             = $request->user();
        $requestedStoreId = $request->integer('store_id') ?: null;

        if ($user->isAdmin()) {
            if ($requestedStoreId) {
                return Store::where('store_id', $requestedStoreId)->exists()
                    ? [(int) $requestedStoreId]
                    : [];
            }

            return Store::select('store_id')
                ->pluck('store_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $allowedIds = $user->stores()->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($requestedStoreId) {
            return in_array((int) $requestedStoreId, $allowedIds, true)
                ? [(int) $requestedStoreId]
                : [];
        }

        return $allowedIds;
    }

    public function superAdmin(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.dashboard')) return $error;

        $storeIds = $this->resolveStoreIds($request);

        if (empty($storeIds)) {
            return response()->json($this->emptyPayload());
        }

        $now       = Carbon::now();
        $today     = $now->toDateString();
        $thirtyAgo = $now->copy()->subDays(30);
        $sixtyAgo  = $now->copy()->subDays(60);
        $sevenAgo  = $now->copy()->subDays(6)->toDateString();

        $stores = Store::select([
            'store_id', 'store_name', 'location', 'physical_address',
            'currency', 'is_active', 'created_at', 'updated_at',
        ])
            ->whereIntegerInRaw('store_id', $storeIds)
            ->get();

        $storeIds = $stores->pluck('store_id')->map(fn ($id) => (int) $id)->all();

        if (empty($storeIds)) {
            return response()->json($this->emptyPayload());
        }

        $activeTenants     = $stores->where('is_active', true);
        $activeTenantCount = $activeTenants->count();
        $totalStores       = $stores->count();

        [$newTenants30, $prevTenants30, $churnedTenants30] =
            $this->tenantDateKpis($stores, $thirtyAgo, $sixtyAgo, $now);

        $signupRate = $prevTenants30 > 0
            ? (($newTenants30 - $prevTenants30) / $prevTenants30) * 100
            : ($newTenants30 > 0 ? 100.0 : 0.0);

        $churnBase = $activeTenantCount + $churnedTenants30;
        $churnRate = $churnBase > 0 ? ($churnedTenants30 / $churnBase) * 100 : 0.0;

        [$productCount, $customerCount, $staffCount] = [
            Product::whereIntegerInRaw('store_id', $storeIds)->count(),
            Customer::whereIntegerInRaw('store_id', $storeIds)->count(),
            User::where('role', '!=', 'admin')
                ->where(function ($q) use ($storeIds) {
                    $q->whereIntegerInRaw('default_store_id', $storeIds)
                      ->orWhereHas('stores', fn ($r) =>
                          $r->whereIntegerInRaw('stores.store_id', $storeIds));
                })->count(),
        ];

        $billingAgg = DB::table('billing')
            ->select($this->billingSelectColumns($today))
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        [
            $grossBilled, $paidCollections, $outstandingTotal,
            $totalOrders, $openBalancesCount,
            $todayCollected, $todayOrders,
            $todayRefundValue, $todayRefundCount,
            $todayVoids, $todayOutstanding,
        ] = $this->sumBillingAgg($billingAgg);

        $averageTicket         = $totalOrders       > 0 ? $paidCollections / $totalOrders       : 0.0;
        $collectionRate        = $grossBilled        > 0 ? ($paidCollections / $grossBilled) * 100 : 0.0;
        $avgOrdersPerTenant    = $activeTenantCount  > 0 ? $totalOrders     / $activeTenantCount  : 0.0;
        $avgCustomersPerTenant = $activeTenantCount  > 0 ? $customerCount   / $activeTenantCount  : 0.0;
        $avgRevenuePerTenant   = $activeTenantCount  > 0 ? $paidCollections / $activeTenantCount  : 0.0;

        $customerOutstanding = (float) DB::table('customers')
            ->whereIntegerInRaw('store_id', $storeIds)
            ->where('current_balance', '>', 0)
            ->sum('current_balance');

        $last7Raw = DB::table('billing')
            ->select([
                DB::raw('DATE(billing_date) AS day'),
                DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS collected"),
                DB::raw("SUM(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN `total` ELSE 0 END) AS billed"),
                DB::raw("SUM(CASE WHEN is_draft = 0 AND balance_due > 0 THEN balance_due ELSE 0 END) AS outstanding"),
                DB::raw("SUM(CASE WHEN `status` IN ('refund','refunded') THEN `total` ELSE 0 END) AS refunds"),
            ])
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw('DATE(billing_date)'), [$sevenAgo, $today])
            ->groupBy(DB::raw('DATE(billing_date)'))
            ->get()
            ->keyBy('day');

        $last7Days      = [];
        $collectedTotal = 0.0;

        foreach (CarbonPeriod::create($sevenAgo, $today) as $date) {
            $key       = $date->toDateString();
            $row       = $last7Raw->get($key);
            $collected = (float) ($row->collected ?? 0);
            $collectedTotal += $collected;

            $last7Days[] = [
                'key'         => $key,
                'label'       => $date->format('D'),
                'label_short' => $date->format('d M'),
                'collected'   => round($collected, 2),
                'billed'      => round((float) ($row->billed      ?? 0), 2),
                'outstanding' => round((float) ($row->outstanding ?? 0), 2),
                'refunds'     => round((float) ($row->refunds     ?? 0), 2),
                'amount'      => round($collected, 2),
            ];
        }

        $dayCount         = count($last7Days);
        $projectedMonthly = $dayCount > 0 ? ($collectedTotal / $dayCount) * 30 : 0.0;

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
                    'revenue'     => (float) ($rev->revenue    ?? 0),
                    'orders'      => (int)   ($rev->orders     ?? 0),
                    'outstanding' => (int)   ($rev->outstanding ?? 0),
                    'lowStock'    => (int)   ($low->low_count  ?? 0),
                ];
            })
            ->all();

        usort($storePerformance, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $storePerformance = array_slice($storePerformance, 0, 8);

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

        $newTenantsToday = User::where('role', '!=', 'admin')
            ->whereDate('created_at', $today)
            ->where(function ($q) use ($storeIds) {
                $q->whereIntegerInRaw('default_store_id', $storeIds)
                  ->orWhereHas('stores', fn ($r) =>
                      $r->whereIntegerInRaw('stores.store_id', $storeIds));
            })
            ->count();

            $lowStockRows = DB::table('inventory')
    ->join('products', 'inventory.product_id', '=', 'products.product_id')
    ->select([
        'inventory.inventory_id',
        'inventory.store_id',
        'inventory.product_id',
        'inventory.quantity',
        'inventory.reorder_level',
        'products.product_name',
    ])
    ->whereIntegerInRaw('inventory.store_id', $storeIds)
    ->whereColumn('inventory.quantity', '<=', 'inventory.reorder_level')
    ->orderBy('inventory.quantity')
    ->limit(5)
    ->get()
    ->map(fn ($r) => (array) $r)
    ->all();

        $currency = $activeTenants->first()?->currency
            ?? $stores->first()?->currency
            ?? 'KES';

        return response()->json([
            'currency' => $currency,
            'summary'  => [
                'platform' => [
                    'mrr'                => 0.00,
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
                    'outstanding'  => round($customerOutstanding, 2),
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
                'store_performance' => $storePerformance,
                'low_stock_rows'    => $lowStockRows,  
            ],
            'trends' => [
                'last_7_days' => $last7Days,
            ],
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.dashboard')) return $error;

        $storeIds = $this->resolveStoreIds($request);

        if (empty($storeIds)) {
            return response()->json(['trends' => ['last_7_days' => []]]);
        }

        $today    = now()->toDateString();
        $sevenAgo = now()->subDays(6)->toDateString();

        $raw = DB::table('billing')
            ->select([
                DB::raw('DATE(billing_date) AS day'),
                DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS collected"),
                DB::raw("SUM(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN `total` ELSE 0 END) AS billed"),
                DB::raw("SUM(CASE WHEN is_draft = 0 AND balance_due > 0 THEN balance_due ELSE 0 END) AS outstanding"),
                DB::raw("SUM(CASE WHEN `status` IN ('refund','refunded') THEN `total` ELSE 0 END) AS refunds"),
            ])
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw('DATE(billing_date)'), [$sevenAgo, $today])
            ->groupBy(DB::raw('DATE(billing_date)'))
            ->get()
            ->keyBy('day');

        $days = [];
        foreach (CarbonPeriod::create($sevenAgo, $today) as $date) {
            $key       = $date->toDateString();
            $row       = $raw->get($key);
            $collected = (float) ($row->collected ?? 0);

            $days[] = [
                'key'         => $key,
                'label'       => $date->format('D'),
                'label_short' => $date->format('d M'),
                'collected'   => round($collected, 2),
                'billed'      => round((float) ($row->billed      ?? 0), 2),
                'outstanding' => round((float) ($row->outstanding ?? 0), 2),
                'refunds'     => round((float) ($row->refunds     ?? 0), 2),
                'amount'      => round($collected, 2),
            ];
        }

        return response()->json(['trends' => ['last_7_days' => $days]]);
    }

    public function operations(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.dashboard')) return $error;

        $startTime     = microtime(true);
        $errorRate     = 0.0;
        $incidentCount = 0;
        $backgroundJobs = [];

        try {
            $jobStats = DB::table('jobs')
                ->selectRaw('COUNT(*) AS total')
                ->first();

            $failedCount = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHours(24))
                ->count();

            $totalJobs = (int) ($jobStats->total ?? 0);
            $errorRate = $totalJobs > 0
                ? round(($failedCount / max($totalJobs + $failedCount, 1)) * 100, 2)
                : 0.0;

            $incidentCount = $failedCount;

            $queued = DB::table('jobs')
                ->select('queue', DB::raw('COUNT(*) AS pending'))
                ->groupBy('queue')
                ->get()
                ->keyBy('queue');

            $failed = DB::table('failed_jobs')
                ->select('queue', DB::raw('COUNT(*) AS failed'))
                ->where('failed_at', '>=', now()->subHours(24))
                ->groupBy('queue')
                ->get()
                ->keyBy('queue');

            foreach ($queued->keys()->merge($failed->keys())->unique() as $queue) {
                $pending = (int) ($queued->get($queue)?->pending ?? 0);
                $fail    = (int) ($failed->get($queue)?->failed  ?? 0);

                $backgroundJobs[] = [
                    'name'        => ucfirst($queue) . ' queue',
                    'running'     => 0,
                    'pending'     => $pending,
                    'failed'      => $fail,
                    'status'      => $fail    > 0 ? 'failed'
                                   : ($pending > 0 ? 'pending' : 'healthy'),
                    'last_run_at' => null,
                ];
            }
        } catch (\Throwable) {
            // silently degrade — operations panel is non-critical
        }

        $latencyMs = round((microtime(true) - $startTime) * 1000, 1);

        return response()->json([
            'operations' => [
                'system_health' => [
                    'api_latency_ms'       => $latencyMs,
                    'api_error_rate'       => $errorRate,
                    'webhook_success_rate' => 100.0,
                    'incident_count'       => $incidentCount,
                ],
                'background_jobs' => $backgroundJobs,
            ],
        ]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.dashboard')) return $error;

        return response()->json([
            'subscriptions' => [
                'subscription_distribution' => [],
            ],
        ]);
    }

    public function security(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.access_control')) return $error;

        $storeIds = $this->resolveStoreIds($request);
        $perPage  = max(1, min((int) ($request->per_page ?? 3), 100));

        $query = AuditLog::with('user:user_id,first_name,last_name')
            ->latest('created_at');

        if (! empty($storeIds)) {
            $query->whereIntegerInRaw('store_id', $storeIds);
        }

        $paginated = $query->paginate($perPage);

        $events = $paginated->getCollection()->map(fn ($log) => [
            'id'         => $log->audit_log_id,
            'action'     => $log->action,
            'message'    => $log->action,
            'level'      => 'info',
            'actor'      => trim(($log->user?->first_name ?? '') . ' ' . ($log->user?->last_name ?? '')) ?: 'System',
            'store_id'   => $log->store_id,
            'created_at' => $log->created_at,
        ]);

        return response()->json([
            'security' => [
                'audit_events' => $events,
                'meta' => [
                    'current_page'  => $paginated->currentPage(),
                    'last_page'     => $paginated->lastPage(),
                    'per_page'      => $paginated->perPage(),
                    'total'         => $paginated->total(),
                    'from'          => $paginated->firstItem(),
                    'to'            => $paginated->lastItem(),
                    'has_prev_page' => $paginated->currentPage() > 1,
                    'has_next_page' => $paginated->hasMorePages(),
                ],
            ],
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function tenantDateKpis($stores, Carbon $thirtyAgo, Carbon $sixtyAgo, Carbon $now): array
    {
        $new = $prev = $churned = 0;

        foreach ($stores as $s) {
            $created = $s->created_at ? Carbon::parse($s->created_at) : null;
            $updated = $s->updated_at ? Carbon::parse($s->updated_at) : null;

            if ($created && $created->gte($thirtyAgo) && $created->lt($now)) $new++;
            if ($created && $created->gte($sixtyAgo)  && $created->lt($thirtyAgo)) $prev++;
            if (! $s->is_active && $updated && $updated->gte($thirtyAgo) && $updated->lt($now)) $churned++;
        }

        return [$new, $prev, $churned];
    }

    private function billingSelectColumns(string $today): array
    {
        return [
            'store_id',
            DB::raw("SUM(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN `total` ELSE 0 END) AS gross_billed"),
            DB::raw("SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS paid_collections"),
            DB::raw("SUM(CASE WHEN is_draft = 0 AND balance_due > 0 THEN balance_due ELSE 0 END) AS outstanding_total"),
            DB::raw("COUNT(CASE WHEN `status` != 'draft' AND is_draft = 0 THEN 1 END) AS total_orders"),
            DB::raw("COUNT(CASE WHEN balance_due > 0 AND is_draft = 0 THEN 1 END) AS open_balances_count"),
            DB::raw("SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS today_collected"),
            DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` != 'draft' AND is_draft = 0 THEN 1 END) AS today_orders"),
            DB::raw("SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('refund','refunded') THEN `total` ELSE 0 END) AS today_refund_value"),
            DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('refund','refunded') THEN 1 END) AS today_refund_count"),
            DB::raw("COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND is_draft = 1 THEN 1 END) AS today_voids"),
            DB::raw("SUM(CASE WHEN is_draft = 0 AND balance_due > 0 THEN balance_due ELSE 0 END) AS today_outstanding"),
        ];
    }

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

    private function emptyPayload(): array
    {
        return [
            'currency' => 'KES',
            'summary'  => [
                'platform' => [
                    'mrr' => 0, 'active_tenants' => 0, 'total_tenants' => 0,
                    'new_tenants_30' => 0, 'prev_tenants_30' => 0, 'signup_rate' => 0,
                    'churned_tenants_30' => 0, 'churn_rate' => 0,
                ],
                'today' => [
                    'collected' => 0, 'orders' => 0, 'refund_value' => 0,
                    'refund_count' => 0, 'voids' => 0, 'outstanding' => 0, 'new_tenants' => 0,
                ],
                'stats' => [
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
                'store_performance' => [],
                'low_stock_rows'    => [], 
            ],
            'trends' => ['last_7_days' => []],
        ];
    }
}