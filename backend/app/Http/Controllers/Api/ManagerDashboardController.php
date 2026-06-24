<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManagerDashboardController extends Controller
{
    /**
     * Cache Schema::hasColumn lookups per request.
     */
    private array $columnExistsCache = [];

    /**
     * Resolve only stores the current user is allowed to view.
     * - Admin: can view all stores or one requested store
     * - Non-admin: only linked/default stores
     * - If store_id is passed but not allowed => 403
     */
    private function resolveStoreIds(Request $request): array
    {
        $user = $request->user();
        $requestedStoreId = $request->integer('store_id') ?: null;

        if ($user->isAdmin()) {
            $allIds = Store::query()
                ->pluck('store_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($requestedStoreId) {
                abort_unless(
                    in_array((int) $requestedStoreId, $allIds, true),
                    403,
                    'You are not allowed to view this store.'
                );

                return [(int) $requestedStoreId];
            }

            return $allIds;
        }

        $allowedIds = $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($requestedStoreId) {
            abort_unless(
                in_array((int) $requestedStoreId, $allowedIds, true),
                403,
                'You are not allowed to view this store.'
            );

            return [(int) $requestedStoreId];
        }

        return $allowedIds;
    }

    /**
     * ─────────────────────────────────────────────────────────────────────
     * SUMMARY
     * Main KPI block for manager dashboard
     * ─────────────────────────────────────────────────────────────────────
     */
    public function summary(Request $request): JsonResponse
    {
        $storeIds = $this->resolveStoreIds($request);

        if (empty($storeIds)) {
            return response()->json($this->emptySummaryPayload());
        }

        $now         = Carbon::now();
        $today       = $now->toDateString();
        $yesterday   = $now->copy()->subDay()->toDateString();
        $monthStart  = $now->copy()->startOfMonth()->toDateString();
        $dayOfMonth  = max((int) $now->day, 1);
        $daysInMonth = (int) $now->daysInMonth;

        $stores = Store::query()
            ->select(['store_id', 'store_name', 'currency'])
            ->whereIntegerInRaw('store_id', $storeIds)
            ->get();

        $currency = $stores->first()?->currency ?? 'KES';

        $loyaltyIssuedCol = $this->firstExistingColumn('billing', [
            'loyalty_points_issued',
            'loyalty_points_earned',
            'points_earned',
            'points_awarded',
        ]);

        $loyaltyRedeemedCol = $this->firstExistingColumn('billing', [
            'loyalty_points_redeemed',
            'points_redeemed',
            'redeemed_points',
        ]);

        $billingAgg = DB::table('billing')
            ->selectRaw("
                SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS today_sales,
                SUM(CASE WHEN DATE(billing_date) = '{$yesterday}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS yesterday_sales,

                SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('refund','refunded','returned','return') THEN `total` ELSE 0 END) AS today_refunds,
                SUM(CASE WHEN DATE(billing_date) = '{$yesterday}' AND `status` IN ('refund','refunded','returned','return') THEN `total` ELSE 0 END) AS yesterday_refunds,

                COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN 1 END) AS today_transactions,
                COUNT(CASE WHEN DATE(billing_date) = '{$yesterday}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN 1 END) AS yesterday_transactions,

                COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND (is_draft = 1 OR `status` IN ('void','voided','cancelled','canceled')) THEN 1 END) AS today_voids,
                COUNT(CASE WHEN DATE(billing_date) = '{$yesterday}' AND (is_draft = 1 OR `status` IN ('void','voided','cancelled','canceled')) THEN 1 END) AS yesterday_voids,

                COUNT(CASE WHEN (is_draft = 1 OR `status` IN ('draft','parked','quote','pending')) THEN 1 END) AS pending_orders_count,

                COUNT(DISTINCT CASE
                    WHEN DATE(billing_date) = '{$today}' AND `status` IN ('paid','partial') AND is_draft = 0
                    THEN customer_id
                END) AS unique_customers_today,

                SUM(CASE
                    WHEN DATE(billing_date) >= '{$monthStart}' AND DATE(billing_date) <= '{$today}'
                    AND `status` IN ('paid','partial') AND is_draft = 0
                    THEN paid_amount ELSE 0
                END) AS month_sales
            ")
            ->when($loyaltyIssuedCol, function ($q) use ($today, $loyaltyIssuedCol) {
                $q->selectRaw("
                    SUM(CASE WHEN DATE(billing_date) = '{$today}' THEN COALESCE(`{$loyaltyIssuedCol}`, 0) ELSE 0 END) AS loyalty_issued_today
                ");
            }, function ($q) {
                $q->selectRaw("0 AS loyalty_issued_today");
            })
            ->when($loyaltyRedeemedCol, function ($q) use ($today, $loyaltyRedeemedCol) {
                $q->selectRaw("
                    SUM(CASE WHEN DATE(billing_date) = '{$today}' THEN COALESCE(`{$loyaltyRedeemedCol}`, 0) ELSE 0 END) AS loyalty_redeemed_today
                ");
            }, function ($q) {
                $q->selectRaw("0 AS loyalty_redeemed_today");
            })
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->first();

        $itemCostExpr   = $this->itemCostExpression('bi', 'p');
        $lineAmountExpr = $this->lineAmountExpression('bi');

        $costAgg = DB::table('billing_items as bi')
            ->join('billing as b', 'b.billing_id', '=', 'bi.billing_id')
            ->leftJoin('products as p', 'p.product_id', '=', 'bi.product_id')
            ->selectRaw("
                SUM(CASE
                    WHEN DATE(b.billing_date) = '{$today}' AND b.`status` IN ('paid','partial') AND b.is_draft = 0
                    THEN {$itemCostExpr}
                    ELSE 0
                END) AS today_cost,

                SUM(CASE
                    WHEN DATE(b.billing_date) = '{$yesterday}' AND b.`status` IN ('paid','partial') AND b.is_draft = 0
                    THEN {$itemCostExpr}
                    ELSE 0
                END) AS yesterday_cost
            ")
            ->whereIntegerInRaw('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->first();

        $inventoryValueExpr = $this->inventoryValueExpression('i', 'p');

        $inventoryAgg = DB::table('inventory as i')
            ->leftJoin('products as p', 'p.product_id', '=', 'i.product_id')
            ->selectRaw("
                COUNT(*) AS total_rows,
                SUM(CASE WHEN i.quantity <= i.reorder_level THEN 1 ELSE 0 END) AS low_stock_count,
                SUM(CASE WHEN i.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
                SUM(COALESCE(i.quantity, 0)) AS total_inventory_units,
                SUM({$inventoryValueExpr}) AS total_inventory_value
            ")
            ->whereIntegerInRaw('i.store_id', $storeIds)
            ->first();

$activeStaffCount = DB::table('users')
    ->where('role', '!=', 'admin')
    ->where('is_active', true)
    ->where(function ($q) use ($storeIds) {
        $q->whereIntegerInRaw('default_store_id', $storeIds);

        // Only join store_user pivot if the table actually exists
        if (Schema::hasTable('store_user')) {
            $q->orWhereExists(function ($sub) use ($storeIds) {
                $sub->selectRaw('1')
                    ->from('store_user')
                    ->whereColumn('store_user.user_id', 'users.user_id')
                    ->whereIntegerInRaw('store_user.store_id', $storeIds);
            });
        }
    })
    ->count();

        $newCustomersTodayQuery = DB::table('customers')
            ->whereDate('created_at', $today);

        if ($this->hasColumn('customers', 'store_id')) {
            $newCustomersTodayQuery->whereIntegerInRaw('store_id', $storeIds);
        }

        $newCustomersToday = $newCustomersTodayQuery->count();

        $productNameExpr = $this->productNameExpression('p');

        $topItems = DB::table('billing_items as bi')
            ->join('billing as b', 'b.billing_id', '=', 'bi.billing_id')
            ->leftJoin('products as p', 'p.product_id', '=', 'bi.product_id')
            ->selectRaw("
                bi.product_id,
                {$productNameExpr} AS product_name,
                SUM({$this->itemQtyExpression('bi')}) AS qty,
                SUM({$lineAmountExpr}) AS amount
            ")
            ->whereIntegerInRaw('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->whereDate('b.billing_date', '>=', $monthStart)
            ->where('b.is_draft', 0)
            ->whereIn('b.status', ['paid', 'partial'])
            ->groupBy('bi.product_id', DB::raw($productNameExpr))
            ->orderByDesc('qty')
            ->orderByDesc('amount')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'name'       => $row->product_name ?: 'Unnamed item',
                'qty'        => (float) $row->qty,
                'amount'     => round((float) $row->amount, 2),
            ])
            ->values();

        $billingUserColumn = $this->firstExistingColumn('billing', [
            'user_id',
            'cashier_id',
            'processed_by',
            'created_by',
        ]);

        $userNameExpr = $billingUserColumn
            ? $this->userNameExpression('u')
            : "'Unknown cashier'";

        $cashierQuery = DB::table('billing as b');

        if ($billingUserColumn) {
            $cashierQuery->leftJoin('users as u', "u.user_id", '=', "b.{$billingUserColumn}");
        }

        $cashierPerformance = $cashierQuery
            ->selectRaw("
                {$userNameExpr} AS cashier_name,
                COUNT(*) AS orders,
                SUM(CASE WHEN b.`status` IN ('paid','partial') AND b.is_draft = 0 THEN b.paid_amount ELSE 0 END) AS revenue
            ")
            ->whereIntegerInRaw('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->whereDate('b.billing_date', '>=', $monthStart)
            ->where('b.is_draft', 0)
            ->whereIn('b.status', ['paid', 'partial'])
            ->groupBy(DB::raw($userNameExpr))
            ->orderByDesc('revenue')
            ->orderByDesc('orders')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'name'    => $row->cashier_name ?: 'Unknown cashier',
                'orders'  => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values();

        $registerLabelExpr = $this->registerLabelExpression('b');

        $registerPerformance = DB::table('billing as b')
            ->selectRaw("
                {$registerLabelExpr} AS register_name,
                COUNT(*) AS orders,
                SUM(CASE WHEN b.`status` IN ('paid','partial') AND b.is_draft = 0 THEN b.paid_amount ELSE 0 END) AS collected
            ")
            ->whereIntegerInRaw('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->whereDate('b.billing_date', $today)
            ->where('b.is_draft', 0)
            ->whereIn('b.status', ['paid', 'partial'])
            ->groupBy(DB::raw($registerLabelExpr))
            ->orderByDesc('collected')
            ->orderByDesc('orders')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'name'      => $row->register_name ?: 'POS Terminal',
                'orders'    => (int) $row->orders,
                'collected' => round((float) $row->collected, 2),
            ])
            ->values();

        $todaySales     = (float) ($billingAgg->today_sales ?? 0);
        $yesterdaySales = (float) ($billingAgg->yesterday_sales ?? 0);

        $todayRefunds     = (float) ($billingAgg->today_refunds ?? 0);
        $yesterdayRefunds = (float) ($billingAgg->yesterday_refunds ?? 0);

        $todayCost     = (float) ($costAgg->today_cost ?? 0);
        $yesterdayCost = (float) ($costAgg->yesterday_cost ?? 0);

        $todayNet     = $todaySales - $todayRefunds;
        $yesterdayNet = $yesterdaySales - $yesterdayRefunds;

        $todayProfit     = $todayNet - $todayCost;
        $yesterdayProfit = $yesterdayNet - $yesterdayCost;

        $todayTransactions = (int) ($billingAgg->today_transactions ?? 0);
        $avgTicket         = $todayTransactions > 0 ? $todaySales / $todayTransactions : 0.0;

        $lowStockCount     = (int) ($inventoryAgg->low_stock_count ?? 0);
        $outOfStockCount   = (int) ($inventoryAgg->out_of_stock_count ?? 0);
        $totalRows         = (int) ($inventoryAgg->total_rows ?? 0);
        $healthyStockCount = max($totalRows - $lowStockCount, 0);
        $inventoryHealth   = $totalRows > 0 ? ($healthyStockCount / $totalRows) * 100 : 0.0;

        $monthSales            = (float) ($billingAgg->month_sales ?? 0);
        $monthlyProjectedSales = $dayOfMonth > 0 ? ($monthSales / $dayOfMonth) * $daysInMonth : 0.0;
        $averageMargin         = $todayNet > 0 ? ($todayProfit / $todayNet) * 100 : 0.0;

        return response()->json([
            'currency' => $currency,
            'summary'  => [
                'today' => [
                    'gross_sales'       => round($todaySales, 2),
                    'gross_sales_prev'  => round($yesterdaySales, 2),
                    'refund_value'      => round($todayRefunds, 2),
                    'refund_value_prev' => round($yesterdayRefunds, 2),
                    'void_count'        => (int) ($billingAgg->today_voids ?? 0),
                    'void_count_prev'   => (int) ($billingAgg->yesterday_voids ?? 0),
                    'transactions'      => $todayTransactions,
                    'transactions_prev' => (int) ($billingAgg->yesterday_transactions ?? 0),
                    'net_sales'         => round($todayNet, 2),
                    'net_sales_prev'    => round($yesterdayNet, 2),
                    'cost'              => round($todayCost, 2),
                    'cost_prev'         => round($yesterdayCost, 2),
                    'profit'            => round($todayProfit, 2),
                    'profit_prev'       => round($yesterdayProfit, 2),
                    'avg_ticket'        => round($avgTicket, 2),
                    'pending_orders'    => (int) ($billingAgg->pending_orders_count ?? 0),
                    'unique_customers'  => (int) ($billingAgg->unique_customers_today ?? 0),
                ],
                'stats' => [
                    'inventory_health_pct'   => round($inventoryHealth, 2),
                    'monthly_projected_sales'=> round($monthlyProjectedSales, 2),
                    'average_margin'         => round($averageMargin, 2),
                    'active_registers'       => $registerPerformance->count(),
                    'active_staff'           => $activeStaffCount,
                    'total_inventory_units'  => (float) ($inventoryAgg->total_inventory_units ?? 0),
                    'total_inventory_value'  => round((float) ($inventoryAgg->total_inventory_value ?? 0), 2),
                    'low_stock_count'        => $lowStockCount,
                    'out_of_stock_count'     => $outOfStockCount,
                    'healthy_stock_count'    => $healthyStockCount,
                    'total_inventory_rows'   => $totalRows,
                ],
                'loyalty' => [
                    'new_customers_today' => $newCustomersToday,
                    'issued_today'        => round((float) ($billingAgg->loyalty_issued_today ?? 0), 2),
                    'redeemed_today'      => round((float) ($billingAgg->loyalty_redeemed_today ?? 0), 2),
                ],
                'top_items'            => $topItems,
                'cashier_performance'  => $cashierPerformance,
                'register_performance' => $registerPerformance,
            ],
        ]);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────
     * TRENDS
     * Last 7 days sales/refunds/cost/profit/net
     * ─────────────────────────────────────────────────────────────────────
     */
    public function trends(Request $request): JsonResponse
    {
        $storeIds = $this->resolveStoreIds($request);

        if (empty($storeIds)) {
            return response()->json(['trends' => ['last_7_days' => []]]);
        }

        $today    = now()->toDateString();
        $sevenAgo = now()->subDays(6)->toDateString();

        $salesRaw = DB::table('billing')
            ->selectRaw("
                DATE(billing_date) AS day,
                SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS sales,
                SUM(CASE WHEN `status` IN ('refund','refunded','returned','return') THEN `total` ELSE 0 END) AS refunds
            ")
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw('DATE(billing_date)'), [$sevenAgo, $today])
            ->groupBy(DB::raw('DATE(billing_date)'))
            ->get()
            ->keyBy('day');

        $itemCostExpr = $this->itemCostExpression('bi', 'p');

        $costRaw = DB::table('billing_items as bi')
            ->join('billing as b', 'b.billing_id', '=', 'bi.billing_id')
            ->leftJoin('products as p', 'p.product_id', '=', 'bi.product_id')
            ->selectRaw("
                DATE(b.billing_date) AS day,
                SUM(CASE WHEN b.`status` IN ('paid','partial') AND b.is_draft = 0 THEN {$itemCostExpr} ELSE 0 END) AS cost
            ")
            ->whereIntegerInRaw('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->whereBetween(DB::raw('DATE(b.billing_date)'), [$sevenAgo, $today])
            ->groupBy(DB::raw('DATE(b.billing_date)'))
            ->get()
            ->keyBy('day');

        $days = [];

        foreach (CarbonPeriod::create($sevenAgo, $today) as $date) {
            $key     = $date->toDateString();
            $sales   = (float) ($salesRaw->get($key)?->sales ?? 0);
            $refunds = (float) ($salesRaw->get($key)?->refunds ?? 0);
            $cost    = (float) ($costRaw->get($key)?->cost ?? 0);
            $net     = $sales - $refunds;
            $profit  = $net - $cost;

            $days[] = [
                'key'         => $key,
                'label'       => $date->format('D'),
                'label_short' => $date->format('d M'),
                'sales'       => round($sales, 2),
                'refunds'     => round($refunds, 2),
                'cost'        => round($cost, 2),
                'profit'      => round($profit, 2),
                'net'         => round($net, 2),
                'amount'      => round($net, 2),
            ];
        }

        return response()->json([
            'trends' => [
                'last_7_days' => $days,
            ],
        ]);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────
     * ACTIVITY
     * Recent billings, pending orders, low stock alerts
     * ─────────────────────────────────────────────────────────────────────
     */
    public function activity(Request $request): JsonResponse
    {
        $storeIds = $this->resolveStoreIds($request);

        if (empty($storeIds)) {
            return response()->json([
                'activity' => [
                    'recent'         => [],
                    'pending_orders' => [],
                    'low_stock_rows' => [],
                ],
            ]);
        }

        $customerNameExpr = $this->customerNameExpression('c');
        $productNameExpr  = $this->productNameExpression('p');

        $recent = DB::table('billing as b')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'b.customer_id')
            ->selectRaw("
                b.billing_id,
                b.invnumber,
                b.`status`,
                b.is_draft,
                b.`total`,
                b.paid_amount,
                b.billing_date,
                {$customerNameExpr} AS customer_name
            ")
            ->whereIntegerInRaw('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->orderByDesc('b.billing_date')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'billing_id'    => (int) $row->billing_id,
                'invnumber'     => $row->invnumber ?: "Draft #{$row->billing_id}",
                'status'        => strtolower((string) ($row->status ?: ($row->is_draft ? 'draft' : 'unknown'))),
                'total'         => round((float) ($row->total ?? 0), 2),
                'paid_amount'   => round((float) ($row->paid_amount ?? 0), 2),
                'billing_date'  => $row->billing_date,
                'customer_name' => $row->customer_name ?: 'Walk-in customer',
            ])
            ->values();

        $pendingOrders = DB::table('billing as b')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'b.customer_id')
            ->selectRaw("
                b.billing_id,
                b.invnumber,
                b.`status`,
                b.is_draft,
                b.`total`,
                b.paid_amount,
                b.billing_date,
                {$customerNameExpr} AS customer_name
            ")
            ->whereIntegerInRaw('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->where(function ($q) {
                $q->where('b.is_draft', 1)
                  ->orWhereIn('b.status', ['draft', 'parked', 'quote', 'pending']);
            })
            ->orderByDesc('b.billing_date')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'billing_id'    => (int) $row->billing_id,
                'invnumber'     => $row->invnumber ?: "Draft #{$row->billing_id}",
                'status'        => strtolower((string) ($row->status ?: ($row->is_draft ? 'draft' : 'unknown'))),
                'total'         => round((float) ($row->total ?? 0), 2),
                'paid_amount'   => round((float) ($row->paid_amount ?? 0), 2),
                'billing_date'  => $row->billing_date,
                'customer_name' => $row->customer_name ?: 'Walk-in customer',
            ])
            ->values();

        $lowStockRows = DB::table('inventory as i')
            ->leftJoin('products as p', 'p.product_id', '=', 'i.product_id')
            ->selectRaw("
                i.inventory_id,
                i.product_id,
                i.store_id,
                i.quantity,
                i.reorder_level,
                {$productNameExpr} AS product_name
            ")
            ->whereIntegerInRaw('i.store_id', $storeIds)
            ->whereColumn('i.quantity', '<=', 'i.reorder_level')
            ->orderByRaw('(COALESCE(i.quantity, 0) - COALESCE(i.reorder_level, 0)) ASC')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'inventory_id'  => (int) $row->inventory_id,
                'product_id'    => $row->product_id ? (int) $row->product_id : null,
                'store_id'      => (int) $row->store_id,
                'product_name'  => $row->product_name ?: 'Unnamed product',
                'quantity'      => (float) ($row->quantity ?? 0),
                'reorder_level' => (float) ($row->reorder_level ?? 0),
            ])
            ->values();

        return response()->json([
            'activity' => [
                'recent'         => $recent,
                'pending_orders' => $pendingOrders,
                'low_stock_rows' => $lowStockRows,
            ],
        ]);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────
     * Empty payloads
     * ─────────────────────────────────────────────────────────────────────
     */
    private function emptySummaryPayload(): array
    {
        return [
            'currency' => 'KES',
            'summary'  => [
                'today' => [
                    'gross_sales'       => 0,
                    'gross_sales_prev'  => 0,
                    'refund_value'      => 0,
                    'refund_value_prev' => 0,
                    'void_count'        => 0,
                    'void_count_prev'   => 0,
                    'transactions'      => 0,
                    'transactions_prev' => 0,
                    'net_sales'         => 0,
                    'net_sales_prev'    => 0,
                    'cost'              => 0,
                    'cost_prev'         => 0,
                    'profit'            => 0,
                    'profit_prev'       => 0,
                    'avg_ticket'        => 0,
                    'pending_orders'    => 0,
                    'unique_customers'  => 0,
                ],
                'stats' => [
                    'inventory_health_pct'    => 0,
                    'monthly_projected_sales' => 0,
                    'average_margin'          => 0,
                    'active_registers'        => 0,
                    'active_staff'            => 0,
                    'total_inventory_units'   => 0,
                    'total_inventory_value'   => 0,
                    'low_stock_count'         => 0,
                    'out_of_stock_count'      => 0,
                    'healthy_stock_count'     => 0,
                    'total_inventory_rows'    => 0,
                ],
                'loyalty' => [
                    'new_customers_today' => 0,
                    'issued_today'        => 0,
                    'redeemed_today'      => 0,
                ],
                'top_items'            => [],
                'cashier_performance'  => [],
                'register_performance' => [],
            ],
        ];
    }

    /**
     * ─────────────────────────────────────────────────────────────────────
     * Dynamic schema helpers
     * ─────────────────────────────────────────────────────────────────────
     */
    private function hasColumn(string $table, string $column): bool
    {
        if (! isset($this->columnExistsCache[$table])) {
            $this->columnExistsCache[$table] = [];
        }

        if (! array_key_exists($column, $this->columnExistsCache[$table])) {
            $this->columnExistsCache[$table][$column] = Schema::hasColumn($table, $column);
        }

        return $this->columnExistsCache[$table][$column];
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if ($this->hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function itemQtyExpression(string $alias = 'bi'): string
    {
        $column = $this->firstExistingColumn('billing_items', [
            'quantity',
            'qty',
            'units',
            'count',
        ]);

        return $column ? "COALESCE({$alias}.`{$column}`, 0)" : '0';
    }

    private function itemPriceExpression(string $alias = 'bi'): string
    {
        $column = $this->firstExistingColumn('billing_items', [
            'unit_price',
            'price',
            'selling_price',
        ]);

        return $column ? "COALESCE({$alias}.`{$column}`, 0)" : '0';
    }

    private function lineAmountExpression(string $billingItemAlias = 'bi'): string
    {
        $explicitColumn = $this->firstExistingColumn('billing_items', [
            'total',
            'line_total',
            'amount',
            'subtotal',
        ]);

        $qtyExpr   = $this->itemQtyExpression($billingItemAlias);
        $priceExpr = $this->itemPriceExpression($billingItemAlias);

        if ($explicitColumn) {
            return "COALESCE({$billingItemAlias}.`{$explicitColumn}`, ({$qtyExpr} * {$priceExpr}), 0)";
        }

        return "({$qtyExpr} * {$priceExpr})";
    }

    private function itemCostExpression(string $billingItemAlias = 'bi', string $productAlias = 'p'): string
    {
        $explicitColumn = $this->firstExistingColumn('billing_items', [
            'cost_total',
            'total_cost',
            'cost_amount',
        ]);

        $qtyExpr = $this->itemQtyExpression($billingItemAlias);

        $unitCostParts = [];

        $billingItemUnitCost = $this->firstExistingColumn('billing_items', [
            'cost_price',
            'unit_cost',
            'buying_price',
            'purchase_price',
        ]);

        if ($billingItemUnitCost) {
            $unitCostParts[] = "{$billingItemAlias}.`{$billingItemUnitCost}`";
        }

        $productUnitCost = $this->firstExistingColumn('products', [
            'cost_price',
            'buying_price',
            'purchase_price',
        ]);

        if ($productUnitCost) {
            $unitCostParts[] = "{$productAlias}.`{$productUnitCost}`";
        }

        $unitCostExpr = ! empty($unitCostParts)
            ? 'COALESCE(' . implode(', ', $unitCostParts) . ', 0)'
            : '0';

        if ($explicitColumn) {
            return "COALESCE({$billingItemAlias}.`{$explicitColumn}`, ({$qtyExpr} * {$unitCostExpr}), 0)";
        }

        return "({$qtyExpr} * {$unitCostExpr})";
    }

    private function inventoryValueExpression(string $inventoryAlias = 'i', string $productAlias = 'p'): string
    {
        $inventoryValueCol = $this->firstExistingColumn('inventory', [
            'stock_value',
            'inventory_value',
            'value',
        ]);

        if ($inventoryValueCol) {
            return "COALESCE({$inventoryAlias}.`{$inventoryValueCol}`, 0)";
        }

        $productCostCol = $this->firstExistingColumn('products', [
            'cost_price',
            'buying_price',
            'purchase_price',
            'selling_price',
            'price',
        ]);

        if ($productCostCol) {
            return "COALESCE({$inventoryAlias}.quantity, 0) * COALESCE({$productAlias}.`{$productCostCol}`, 0)";
        }

        return '0';
    }

    private function productNameExpression(string $alias = 'p'): string
    {
        $parts = [];

        foreach (['product_name', 'name', 'title', 'sku'] as $column) {
            if ($this->hasColumn('products', $column)) {
                $parts[] = "NULLIF(TRIM({$alias}.`{$column}`), '')";
            }
        }

        $parts[] = "'Unnamed item'";

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function customerNameExpression(string $alias = 'c'): string
    {
        $parts = [];
        $nameParts = [];

        if ($this->hasColumn('customers', 'first_name')) {
            $nameParts[] = "NULLIF(TRIM({$alias}.`first_name`), '')";
        }

        if ($this->hasColumn('customers', 'last_name')) {
            $nameParts[] = "NULLIF(TRIM({$alias}.`last_name`), '')";
        }

        if (! empty($nameParts)) {
            $parts[] = "NULLIF(TRIM(CONCAT_WS(' ', " . implode(', ', $nameParts) . ")), '')";
        }

        foreach (['full_name', 'name', 'customer_name', 'phone', 'email'] as $column) {
            if ($this->hasColumn('customers', $column)) {
                $parts[] = "NULLIF(TRIM({$alias}.`{$column}`), '')";
            }
        }

        $parts[] = "'Walk-in customer'";

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function userNameExpression(string $alias = 'u'): string
    {
        $parts = [];
        $nameParts = [];

        if ($this->hasColumn('users', 'first_name')) {
            $nameParts[] = "NULLIF(TRIM({$alias}.`first_name`), '')";
        }

        if ($this->hasColumn('users', 'last_name')) {
            $nameParts[] = "NULLIF(TRIM({$alias}.`last_name`), '')";
        }

        if (! empty($nameParts)) {
            $parts[] = "NULLIF(TRIM(CONCAT_WS(' ', " . implode(', ', $nameParts) . ")), '')";
        }

        foreach (['full_name', 'name', 'email'] as $column) {
            if ($this->hasColumn('users', $column)) {
                $parts[] = "NULLIF(TRIM({$alias}.`{$column}`), '')";
            }
        }

        $parts[] = "'Unknown cashier'";

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function registerLabelExpression(string $alias = 'b'): string
    {
        $parts = [];

        foreach (['register_name', 'till_name', 'terminal_name', 'register_code', 'till_code'] as $column) {
            if ($this->hasColumn('billing', $column)) {
                $parts[] = "NULLIF(TRIM({$alias}.`{$column}`), '')";
            }
        }

        if ($this->hasColumn('billing', 'register_id')) {
            $parts[] = "CASE WHEN {$alias}.`register_id` IS NOT NULL THEN CONCAT('Register #', {$alias}.`register_id`) END";
        }

        if ($this->hasColumn('billing', 'till_id')) {
            $parts[] = "CASE WHEN {$alias}.`till_id` IS NOT NULL THEN CONCAT('Till #', {$alias}.`till_id`) END";
        }

        $parts[] = "'POS Terminal'";

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}
