<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Models\Store;
use App\Services\CashierShiftService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManagerDashboardController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly CashierShiftService $cashierShiftService)
    {
    }

    private array $columnExistsCache = [];

    private function resolveStoreIds(Request $request): array
    {
        $user             = $request->user();
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

    public function summary(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.manager_dashboard')) return $error;

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

    $fulfillmentPendingExpr = $this->hasColumn('billing', 'fulfillment_status')
            ? "(fulfillment_status IS NULL OR fulfillment_status = 'pending')"
            : "0";

        $billingAgg = DB::table('billing')
            ->selectRaw("
                SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS today_sales,
                SUM(CASE WHEN DATE(billing_date) = '{$yesterday}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS yesterday_sales,

                SUM(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('refund','refunded','returned','return') THEN `total` ELSE 0 END) AS today_refunds,
                SUM(CASE WHEN DATE(billing_date) = '{$yesterday}' AND `status` IN ('refund','refunded','returned','return') THEN `total` ELSE 0 END) AS yesterday_refunds,

                COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN 1 END) AS today_transactions,
                COUNT(CASE WHEN DATE(billing_date) = '{$yesterday}' AND `status` IN ('paid','partial') AND is_draft = 0 THEN 1 END) AS yesterday_transactions,

                COUNT(CASE WHEN DATE(billing_date) = '{$today}' AND `status` IN ('void','voided','cancelled','canceled') THEN 1 END) AS today_voids,
                COUNT(CASE WHEN DATE(billing_date) = '{$yesterday}' AND `status` IN ('void','voided','cancelled','canceled') THEN 1 END) AS yesterday_voids,

                COUNT(CASE WHEN {$fulfillmentPendingExpr} THEN 1 END) AS pending_sales_count,

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
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->first();

        $pendingPurchaseOrdersCount = DB::table('purchase_orders')
            ->whereIntegerInRaw('store_id', $storeIds)
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'ordered', 'partially_received'])
            ->count();

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

        $loyaltyAgg = DB::table('loyalty_transactions')
            ->selectRaw("
                SUM(CASE
                    WHEN transaction_type = 'earned' AND points > 0
                    AND DATE(created_at) = '{$today}'
                    THEN points ELSE 0
                END) AS issued_today,
                SUM(CASE
                    WHEN transaction_type = 'redeemed' AND DATE(created_at) = '{$today}'
                    THEN ABS(points) ELSE 0
                END) AS redeemed_today
            ")
            ->whereIntegerInRaw('store_id', $storeIds)
            ->first();

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
            'user_id', 'cashier_id', 'processed_by', 'created_by',
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

        $cashShiftSummary = $this->cashierShiftService->buildScopedDailySummary($storeIds, $today);

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
                    'pending_orders'    => (int) $pendingPurchaseOrdersCount,
                    'pending_sales'     => (int) ($billingAgg->pending_sales_count ?? 0),
                    'unique_customers'  => (int) ($billingAgg->unique_customers_today ?? 0),
                    'opening_balance'   => round((float) ($cashShiftSummary['total_opening_balance'] ?? 0), 2),
                    'cash_sales'        => round((float) ($cashShiftSummary['total_cash_sales'] ?? 0), 2),
                    'non_cash_sales'    => round((float) ($cashShiftSummary['total_non_cash_sales'] ?? 0), 2),
                    'expected_drawer_cash' => round((float) ($cashShiftSummary['total_expected_cash'] ?? 0), 2),
                    'open_cashier_shifts' => (int) ($cashShiftSummary['open_shift_count'] ?? 0),
                    'closed_cashier_shifts' => (int) ($cashShiftSummary['closed_shift_count'] ?? 0),
                ],
                'stats' => [
                    'inventory_health_pct'    => round($inventoryHealth, 2),
                    'monthly_projected_sales' => round($monthlyProjectedSales, 2),
                    'average_margin'          => round($averageMargin, 2),
                    'active_registers'        => $registerPerformance->count(),
                    'active_staff'            => $activeStaffCount,
                    'total_inventory_units'   => (float) ($inventoryAgg->total_inventory_units ?? 0),
                    'total_inventory_value'   => round((float) ($inventoryAgg->total_inventory_value ?? 0), 2),
                    'low_stock_count'         => $lowStockCount,
                    'out_of_stock_count'      => $outOfStockCount,
                    'healthy_stock_count'     => $healthyStockCount,
                    'total_inventory_rows'    => $totalRows,
                ],
                'loyalty' => [
                    'new_customers_today' => $newCustomersToday,
                    'issued_today'        => (int) ($loyaltyAgg->issued_today ?? 0),
                    'redeemed_today'      => (int) ($loyaltyAgg->redeemed_today ?? 0),
                ],
                'top_items'            => $topItems,
                'cashier_performance'  => $cashierPerformance,
                'register_performance' => $registerPerformance,
                'daily_cashier_summary' => $cashShiftSummary['rows'] ?? [],
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

    public function activity(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.dashboard')) return $error;

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

       $customerNameExpr      = $this->customerNameExpression('c');
        $productNameExpr       = $this->productNameExpression('p');
        $fulfillmentStatusExpr = $this->fulfillmentStatusExpression('b');
        $fulfillmentTypeExpr   = $this->fulfillmentTypeExpression('b');

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
                {$customerNameExpr} AS customer_name,
                {$fulfillmentStatusExpr} AS fulfillment_status,
                {$fulfillmentTypeExpr} AS fulfillment_type
            ")
            ->whereIntegerInRaw('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->orderByDesc('b.billing_date')
            ->limit(8)
            ->get()
->map(fn ($row) => [
                'billing_id'         => (int) $row->billing_id,
                'invnumber'          => $row->invnumber ?: "Draft #{$row->billing_id}",
                'status'             => strtolower((string) ($row->status ?: ($row->is_draft ? 'draft' : 'unknown'))),
                'total'              => round((float) ($row->total ?? 0), 2),
                'paid_amount'        => round((float) ($row->paid_amount ?? 0), 2),
                'billing_date'       => $row->billing_date,
                'customer_name'      => $row->customer_name ?: 'Walk-in customer',
                'fulfillment_status' => $row->fulfillment_status ?: 'pending',
                'fulfillment_type'   => $row->fulfillment_type ?: 'walk_in_counter',
            ])
            ->values();

        $purchaseOrderActorExpr = $this->userNameExpression('u');

        $purchaseOrdersBase = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.supplier_id', '=', 'po.supplier_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'po.user_id')
            ->selectRaw("
                po.purchase_order_id,
                po.po_number,
                po.status,
                po.order_date,
                po.expected_delivery_date,
                po.final_total,
                po.created_at,
                po.updated_at,
                po.dispatched_at,
                po.email_sent_at,
                po.completed_at,
                COALESCE(NULLIF(TRIM(s.supplier_name), ''), CONCAT('Supplier #', po.supplier_id)) AS supplier_name,
                {$purchaseOrderActorExpr} AS user_name
            ")
            ->whereIntegerInRaw('po.store_id', $storeIds)
            ->whereNull('po.deleted_at');

        $pendingOrders = (clone $purchaseOrdersBase)
            ->whereIn('po.status', ['draft', 'ordered', 'partially_received'])
            ->orderByDesc(DB::raw('COALESCE(po.updated_at, po.created_at, po.order_date)'))
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'purchase_order_id'       => (int) $row->purchase_order_id,
                'po_number'               => $row->po_number ?: "PO-{$row->purchase_order_id}",
                'status'                  => strtolower((string) ($row->status ?: 'draft')),
                'total'                   => round((float) ($row->final_total ?? 0), 2),
                'supplier_name'           => $row->supplier_name ?: 'Unknown supplier',
                'user_name'               => $row->user_name ?: 'System',
                'order_date'              => $row->order_date,
                'expected_delivery_date'  => $row->expected_delivery_date,
                'pending_at'              => $row->updated_at ?: $row->created_at ?: $row->order_date,
            ])
            ->values();

        $purchaseOrderHistory = (clone $purchaseOrdersBase)
            ->orderByDesc(DB::raw('COALESCE(po.updated_at, po.created_at, po.order_date)'))
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'purchase_order_id' => (int) $row->purchase_order_id,
                'po_number'         => $row->po_number ?: "PO-{$row->purchase_order_id}",
                'status'            => strtolower((string) ($row->status ?: 'draft')),
                'supplier_name'     => $row->supplier_name ?: 'Unknown supplier',
                'user_name'         => $row->user_name ?: 'System',
                'created_at'        => $row->created_at,
                'updated_at'        => $row->updated_at,
                'dispatched_at'     => $row->dispatched_at,
                'email_sent_at'     => $row->email_sent_at,
                'completed_at'      => $row->completed_at,
            ]);

        $auditTrail = $purchaseOrderHistory
            ->flatMap(function (array $row) {
                $events = [];
                $pushEvent = function ($timestamp, string $eventLabel, ?string $statusLabel = null) use (&$events, $row) {
                    if (! $timestamp) {
                        return;
                    }

                    $events[] = [
                        'key'         => md5($row['purchase_order_id'] . '|' . $eventLabel . '|' . $timestamp),
                        'timestamp'   => $timestamp,
                        'event'       => $eventLabel . ' · ' . $row['po_number'],
                        'cashier'     => $row['user_name'] ?: 'System',
                        'authorizer'  => '—',
                        'fulfillment' => ucfirst(str_replace('_', ' ', $statusLabel ?: $row['status'] ?: 'draft')),
                        'requiresAuth'=> false,
                    ];
                };

                $pushEvent($row['created_at'], 'Draft created', 'draft');

                if (! empty($row['updated_at']) && $row['updated_at'] !== $row['created_at']) {
                    $pushEvent($row['updated_at'], 'Draft updated', $row['status']);
                }

                if (! empty($row['dispatched_at'])) {
                    $pushEvent($row['dispatched_at'], 'Order placed', 'ordered');
                }

                if (! empty($row['email_sent_at'])) {
                    $pushEvent($row['email_sent_at'], 'Supplier email sent', $row['status']);
                }

                if (! empty($row['completed_at'])) {
                    $pushEvent($row['completed_at'], 'Order completed', 'completed');
                }

                return $events;
            })
            ->sortByDesc(fn (array $row) => strtotime((string) $row['timestamp']))
            ->take(8)
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
                'audit_trail'    => $auditTrail,
                'low_stock_rows' => $lowStockRows,
            ],
        ]);
    }

public function finalizeShift(Request $request): JsonResponse
{
    if ($error = $this->authorizePermission('page.dashboard')) {
        return $error;
    }

    $validated = $request->validate([
        'store_id'      => ['required', 'integer', 'exists:stores,store_id'],
        'counted_cash'  => ['nullable', 'numeric', 'min:0'],
        'expected_cash' => ['nullable', 'numeric', 'min:0'],
        'variance'      => ['nullable', 'numeric'],
    ]);

    $allowedStoreIds = $this->resolveStoreIds($request);
    $storeId = (int) $validated['store_id'];

    abort_unless(
        in_array($storeId, $allowedStoreIds, true),
        403,
        'You are not allowed to finalize this store.'
    );

    $today = now()->toDateString();
    $now   = now();

    abort_unless(array_key_exists('counted_cash', $validated) && $validated['counted_cash'] !== null, 422, 'Physical drawer count is required before running the final drawer reconciliation.');

    $cashShiftSummary = $this->cashierShiftService->buildScopedDailySummary([$storeId], $today);

    if ((int) ($cashShiftSummary['open_shift_count'] ?? 0) > 0) {
        return response()->json([
            'message' => 'Close each active cashier shift one by one from the cashier report before running the final store Z-Report.',
        ], 422);
    }

    $openVoids = DB::table('billing')
        ->where('store_id', $storeId)
        ->whereNull('deleted_at')
        ->whereDate('billing_date', $today)
        ->whereIn('status', ['void', 'voided', 'cancelled', 'canceled'])
        ->count();

    $pendingOrders = DB::table('billing')
        ->where('store_id', $storeId)
        ->whereNull('deleted_at')
        ->whereDate('billing_date', $today)
        ->where(function ($query) {
            $query->where('is_draft', 1)
                ->orWhereIn('status', ['draft', 'parked', 'quote', 'pending']);
        })
        ->count();

    if ($openVoids > 0 || $pendingOrders > 0) {
        $parts = [];
        if ($openVoids > 0) {
            $parts[] = "{$openVoids} void(s)";
        }
        if ($pendingOrders > 0) {
            $parts[] = "{$pendingOrders} draft/pending sale(s)";
        }

        return response()->json([
            'message' => 'Clear unresolved cashier items before the Drawer Reconciliation can finalize a shift closure: ' . implode(' and ', $parts) . '.',
        ], 422);
    }

    $store = Store::find($storeId);

    $salesAgg = DB::table('billing')
        ->selectRaw("
            COUNT(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN 1 END) AS total_transactions,
            SUM(CASE WHEN `status` IN ('paid','partial') AND is_draft = 0 THEN paid_amount ELSE 0 END) AS gross_sales,
            SUM(CASE WHEN `status` IN ('refund','refunded','returned','return') THEN `total` ELSE 0 END) AS total_refunds,
            COUNT(CASE WHEN `status` IN ('void','voided','cancelled','canceled') THEN 1 END) AS total_voids,
            COUNT(CASE WHEN is_draft = 1 OR `status` IN ('draft','parked') THEN 1 END) AS total_drafts
        ")
        ->where('store_id', $storeId)
        ->whereNull('deleted_at')
        ->whereDate('billing_date', $today)
        ->first();

    $paymentBreakdown = DB::table('payments as p')
        ->join('billing as b', 'b.billing_id', '=', 'p.billing_id')
        ->selectRaw("
            COALESCE(NULLIF(TRIM(p.payment_method), ''), 'Unknown') AS method,
            COUNT(*) AS count,
            SUM(p.amount_received) AS amount
        ")
        ->where('b.store_id', $storeId)
        ->whereNull('b.deleted_at')
        ->whereDate('b.billing_date', $today)
        ->whereIn('b.status', ['paid', 'partial'])
        ->where('b.is_draft', 0)
        ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(p.payment_method), ''), 'Unknown')"))
        ->orderByDesc('amount')
        ->get()
        ->map(fn($r) => [
            'method' => $r->method,
            'count'  => (int) $r->count,
            'amount' => round((float) $r->amount, 2),
        ])
        ->values();

$productsSold = DB::table('billing_items as bi')
    ->join('billing as b', 'b.billing_id', '=', 'bi.billing_id')
    ->leftJoin('products as p', 'p.product_id', '=', 'bi.product_id')
    ->selectRaw("
        bi.product_id,
        COALESCE(NULLIF(TRIM(p.product_name), ''), CONCAT('Product #', bi.product_id)) AS product_name,
        SUM(COALESCE(bi.quantity, 0)) AS qty,
        SUM(COALESCE(bi.total_amount, COALESCE(bi.quantity, 0) * COALESCE(bi.unit_price, 0))) AS amount
    ")
    ->where('b.store_id', $storeId)
    ->whereNull('b.deleted_at')
    ->whereDate('b.billing_date', $today)
    ->whereIn('b.status', ['paid', 'partial'])
    ->where('b.is_draft', 0)
    ->groupBy('bi.product_id', DB::raw("COALESCE(NULLIF(TRIM(p.product_name), ''), CONCAT('Product #', bi.product_id))"))
    ->orderByDesc('qty')
    ->orderByDesc('amount')
    ->get()
        ->map(fn ($r) => [
            'product_id'   => (int) $r->product_id,
            'product_name' => $r->product_name,
            'qty'          => (int) round((float) $r->qty),
            'amount'       => round((float) $r->amount, 2),
        ])
        ->values();

    $grossSales       = (float) ($salesAgg->gross_sales ?? 0);
    $totalRefunds     = (float) ($salesAgg->total_refunds ?? 0);
    $netSales         = $grossSales - $totalRefunds;
    $countedCash      = isset($validated['counted_cash']) ? (float) $validated['counted_cash'] : null;
    $expectedCash     = isset($validated['expected_cash'])
        ? (float) $validated['expected_cash']
        : (float) ($cashShiftSummary['total_expected_cash'] ?? $grossSales);
    $variance         = $countedCash !== null ? $countedCash - $expectedCash : null;

    return response()->json([
        'message' => 'Shift closure finalized successfully.',
        'z_report' => [
            'store_name'         => $store?->store_name ?? 'Store',
            'currency'           => $store?->currency ?? 'KES',
            'date'               => $today,
            'closed_at'          => $now->toISOString(),
            'closed_at_label'    => $now->format('d M Y, H:i'),
            'total_transactions' => (int) ($salesAgg->total_transactions ?? 0),
            'gross_sales'        => round($grossSales, 2),
            'total_refunds'      => round($totalRefunds, 2),
            'net_sales'          => round($netSales, 2),
            'total_voids'        => (int) ($salesAgg->total_voids ?? 0),
            'total_drafts'       => (int) ($salesAgg->total_drafts ?? 0),
            'opening_balance'    => round((float) ($cashShiftSummary['total_opening_balance'] ?? 0), 2),
            'cash_sales'         => round((float) ($cashShiftSummary['total_cash_sales'] ?? 0), 2),
            'non_cash_sales'     => round((float) ($cashShiftSummary['total_non_cash_sales'] ?? 0), 2),
            'expected_cash'      => round($expectedCash, 2),
            'counted_cash'       => $countedCash,
            'variance'           => $variance !== null ? round($variance, 2) : null,
            'payment_breakdown'  => $paymentBreakdown,
            'cashier_shifts'     => $cashShiftSummary['rows'] ?? [],
            'products_sold'      => $productsSold,
            'total_items_sold'   => (int) $productsSold->sum('qty'),
        ],
    ]);
}


    // ── Empty payload ─────────────────────────────────────────────────────

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
                    'pending_sales'     => 0,
                    'unique_customers'  => 0,
                    'opening_balance'   => 0,
                    'cash_sales'        => 0,
                    'non_cash_sales'    => 0,
                    'expected_drawer_cash' => 0,
                    'open_cashier_shifts' => 0,
                    'closed_cashier_shifts' => 0,
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
                'daily_cashier_summary' => [],
            ],
        ];
    }

    // ── Schema helpers ────────────────────────────────────────────────────

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
            'quantity', 'qty', 'units', 'count',
        ]);

        return $column ? "COALESCE({$alias}.`{$column}`, 0)" : '0';
    }

    private function itemPriceExpression(string $alias = 'bi'): string
    {
        $column = $this->firstExistingColumn('billing_items', [
            'unit_price', 'price', 'selling_price',
        ]);

        return $column ? "COALESCE({$alias}.`{$column}`, 0)" : '0';
    }

    private function lineAmountExpression(string $billingItemAlias = 'bi'): string
    {
        $explicitColumn = $this->firstExistingColumn('billing_items', [
            'total', 'line_total', 'amount', 'subtotal',
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
            'cost_total', 'total_cost', 'cost_amount',
        ]);

        $qtyExpr       = $this->itemQtyExpression($billingItemAlias);
        $unitCostParts = [];

        $billingItemUnitCost = $this->firstExistingColumn('billing_items', [
            'cost_price', 'unit_cost', 'buying_price', 'purchase_price',
        ]);

        if ($billingItemUnitCost) {
            $unitCostParts[] = "{$billingItemAlias}.`{$billingItemUnitCost}`";
        }

        $productUnitCost = $this->firstExistingColumn('products', [
            'cost_price', 'buying_price', 'purchase_price',
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
            'stock_value', 'inventory_value', 'value',
        ]);

        if ($inventoryValueCol) {
            return "COALESCE({$inventoryAlias}.`{$inventoryValueCol}`, 0)";
        }

        $productCostCol = $this->firstExistingColumn('products', [
            'cost_price', 'buying_price', 'purchase_price', 'selling_price', 'price',
        ]);

        if ($productCostCol) {
            return "COALESCE({$inventoryAlias}.quantity, 0) * COALESCE({$productAlias}.`{$productCostCol}`, 0)";
        }

        return '0';
    }

private function productNameExpression(string $alias = 'p'): string
{
    $parts = [];

    foreach (['product_name', 'sku'] as $column) {
        if ($this->hasColumn('products', $column)) {
            $parts[] = "NULLIF(TRIM({$alias}.`{$column}`), '')";
        }
    }

    $parts[] = "'Unnamed item'";

    return 'COALESCE(' . implode(', ', $parts) . ')';
}

    private function customerNameExpression(string $alias = 'c'): string
    {
        $parts     = [];
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
        $parts     = [];
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
    private function fulfillmentStatusExpression(string $alias = 'b'): string
{
    return $this->hasColumn('billing', 'fulfillment_status')
        ? "COALESCE({$alias}.fulfillment_status, 'pending')"
        : "'pending'";
}

private function fulfillmentTypeExpression(string $alias = 'b'): string
{
    return $this->hasColumn('billing', 'fulfillment_type')
        ? "COALESCE({$alias}.fulfillment_type, 'walk_in_counter')"
        : "'walk_in_counter'";
}
}