<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ChargeBillingRequest;
use App\Http\Requests\Payment\ChargeCartRequest;
use App\Models\Billing;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly PaymentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.view')) {
            return $error;
        }

        $perPage = max(1, min((int) ($request->per_page ?? 15), 100));
        $filteredPayments = $this->applyLedgerFilters(Payment::query(), $request);
        $profitabilityByBilling = $this->profitabilityByBillingSubquery();

        $payments = (clone $filteredPayments)
            ->leftJoinSub($profitabilityByBilling, 'billing_profit', function ($join) {
                $join->on('billing_profit.billing_id', '=', 'payments.billing_id');
            })
            ->select('payments.*')
            ->selectRaw('COALESCE(billing_profit.gross_sales, 0) as gross_sales_total')
            ->selectRaw('COALESCE(billing_profit.cost_of_goods, 0) as cost_of_goods_total')
            ->selectRaw('COALESCE(billing_profit.gross_profit_total, 0) as gross_profit_total')
            ->selectRaw('COALESCE(billing_profit.units_sold, 0) as profit_units_sold')
            ->with([
                'billing:billing_id,uuid,invnumber,customer_id,store_id,user_id,total,vat_amount,balance_due,points_discount,notes,created_at',
                'billing.customer:customer_id,full_name,email,phone',
                'billing.store:store_id,store_name,currency',
                'billing.user:user_id,first_name,last_name,email',
            ])
            ->orderByDesc('payments.payment_id')
            ->paginate($perPage);

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'from' => $payments->firstItem(),
                'to' => $payments->lastItem(),
            ],
            'summary' => $this->buildLedgerSummary($filteredPayments, $profitabilityByBilling),
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.view')) {
            return $error;
        }

        $payment->load([
            'billing.customer',
            'billing.store',
            'billing.user',
            'billing.items.product.category',
            'billing.payments',
        ]);

        return response()->json([
            'message' => 'Payment retrieved successfully.',
            'data' => $payment,
        ]);
    }

    public function charge(ChargeBillingRequest $request, Billing $billing): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) {
            return $error;
        }

        return response()->json([
            'message' => 'Payment recorded successfully.',
            'data' => $this->service->charge(
                $billing,
                $request->user(),
                $request->validated()
            ),
        ], 201);
    }

    public function chargeCart(ChargeCartRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) {
            return $error;
        }

        $payload = $request->validated();
        $payment = $payload['payment'] ?? [];

        $normalized = [
            'store_id' => $payload['store_id'],
            'customer_id' => $payload['customer_id'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'items' => $payload['items'],
            'points_redeemed' => (int) ($payment['points_redeemed'] ?? 0),
        ];

        if (!empty($payment['allocations']) && is_array($payment['allocations'])) {
            $normalized['payment_allocations'] = array_map(function (array $row) {
                return [
                    'payment_method' => $row['payment_method'] ?? null,
                    'amount_received' => (float) ($row['amount_received'] ?? 0),
                    'amount_tendered' => array_key_exists('amount_tendered', $row)
                        ? (float) $row['amount_tendered']
                        : null,
                    'mpesa_phone' => $row['mpesa_phone'] ?? null,
                    'mpesa_code' => $row['mpesa_code'] ?? null,
                    'mpesa_mode' => $row['mpesa_mode'] ?? null,
                    'card_reference' => $row['card_reference'] ?? null,
                    'card_holder' => $row['card_holder'] ?? null,
                ];
            }, $payment['allocations']);
        } else {
            $normalized = [
                ...$normalized,
                'payment_method' => $payment['method'] ?? null,
                'amount_received' => (float) ($payment['amount_received']
                    ?? $payment['amount_tendered']
                    ?? 0),
                'amount_tendered' => (float) ($payment['amount_tendered'] ?? 0),
                'mpesa_phone' => $payment['mpesa_phone'] ?? null,
                'mpesa_code' => $payment['mpesa_code'] ?? null,
                'card_reference' => $payment['card_reference'] ?? null,
                'card_holder' => $payment['card_holder'] ?? null,
            ];
        }

        $result = $this->service->chargeCart($request->user(), $normalized);

        return response()->json([
            'message' => 'Payment recorded successfully.',
            'data' => $result,
        ], 201);
    }

    private function applyLedgerFilters(Builder $query, Request $request): Builder
    {
        $search = trim((string) $request->search);

        return $query
            ->when($request->filled('store_id'), fn (Builder $q) =>
                $q->whereHas('billing', fn (Builder $billing) =>
                    $billing->where('billing.store_id', (int) $request->store_id)
                )
            )
            ->when($request->filled('status'), fn (Builder $q) =>
                $q->whereHas('billing', fn (Builder $billing) =>
                    $billing->where('billing.status', $request->status)
                )
            )
            ->when($request->filled('payment_method'), fn (Builder $q) =>
                $q->where('payments.payment_method', $request->payment_method)
            )
            ->when($request->filled('date_from'), fn (Builder $q) =>
                $q->whereDate('payments.payment_date', '>=', $request->date_from)
            )
            ->when($request->filled('date_to'), fn (Builder $q) =>
                $q->whereDate('payments.payment_date', '<=', $request->date_to)
            )
            ->when($request->filled('user_id'), fn (Builder $q) =>
                $q->whereHas('billing', fn (Builder $billing) =>
                    $billing->where('billing.user_id', (int) $request->user_id)
                )
            )
            ->when($request->filled('category_id'), fn (Builder $q) =>
                $q->whereHas('billing.items.product', fn (Builder $product) =>
                    $product->where('category_id', (int) $request->category_id)
                )
            )
            ->when($request->filled('product_id'), fn (Builder $q) =>
                $q->whereHas('billing.items', fn (Builder $items) =>
                    $items->where('product_id', (int) $request->product_id)
                )
            )
            ->when($search !== '', function (Builder $q) use ($search) {
                $q->where(function (Builder $sub) use ($search) {
                    $sub->where('receiptnumber', 'like', "%{$search}%")
                        ->orWhereHas('billing', function (Builder $billing) use ($search) {
                            $billing->where('invnumber', 'like', "%{$search}%")
                                ->orWhereHas('customer', function (Builder $customer) use ($search) {
                                    $customer->where('full_name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%")
                                        ->orWhere('phone', 'like', "%{$search}%");
                                })
                                ->orWhereHas('user', function (Builder $user) use ($search) {
                                    $user->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                                });
                        });
                });
            });
    }

    private function profitabilityByBillingSubquery()
    {
        return DB::table('billing_items as bi')
            ->leftJoin('products as products', 'products.product_id', '=', 'bi.product_id')
            ->select('bi.billing_id')
            ->selectRaw('COALESCE(SUM(COALESCE(bi.unit_selling_price, bi.unit_price, 0) * COALESCE(bi.quantity, 0)), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(bi.unit_cost_price, products.cost_price, 0) * COALESCE(bi.quantity, 0)), 0) as cost_of_goods')
            ->selectRaw('COALESCE(SUM((COALESCE(bi.unit_selling_price, bi.unit_price, 0) - COALESCE(bi.unit_cost_price, products.cost_price, 0)) * COALESCE(bi.quantity, 0)), 0) as gross_profit_total')
            ->selectRaw('COALESCE(SUM(COALESCE(bi.quantity, 0)), 0) as units_sold')
            ->groupBy('bi.billing_id');
    }

    private function buildLedgerSummary(Builder $filteredPayments, $profitabilityByBilling): array
    {
        $paymentSummary = (clone $filteredPayments)
            ->selectRaw('COUNT(*) as filtered_count')
            ->selectRaw('COALESCE(SUM(payments.amount_received), 0) as total_received')
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(payments.payment_method) = 'cash' THEN payments.amount_received ELSE 0 END), 0) as cash_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(payments.payment_method) IN ('card', 'visa', 'mastercard', 'pos') THEN payments.amount_received ELSE 0 END), 0) as card_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(payments.payment_method) IN ('mpesa', 'airtel_money', 'wallet', 'digital_wallet', 'bank') THEN payments.amount_received ELSE 0 END), 0) as digital_total")
            ->first();

        $filteredBillingIds = (clone $filteredPayments)
            ->select('payments.billing_id')
            ->distinct();

        $profitabilitySummary = DB::query()
            ->fromSub($profitabilityByBilling, 'billing_profit')
            ->joinSub($filteredBillingIds, 'filtered_billings', function ($join) {
                $join->on('filtered_billings.billing_id', '=', 'billing_profit.billing_id');
            })
            ->selectRaw('COUNT(*) as billed_transactions')
            ->selectRaw('COALESCE(SUM(billing_profit.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(billing_profit.gross_sales), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(billing_profit.cost_of_goods), 0) as cost_of_goods')
            ->selectRaw('COALESCE(SUM(billing_profit.gross_profit_total), 0) as gross_profit_total')
            ->first();

        $filteredCount = (int) ($paymentSummary->filtered_count ?? 0);
        $totalReceived = round((float) ($paymentSummary->total_received ?? 0), 2);
        $grossSales = round((float) ($profitabilitySummary->gross_sales ?? 0), 2);
        $costOfGoods = round((float) ($profitabilitySummary->cost_of_goods ?? 0), 2);
        $grossProfit = round((float) ($profitabilitySummary->gross_profit_total ?? 0), 2);

        return [
            'filtered_count' => $filteredCount,
            'total_received' => $totalReceived,
            'cash_total' => round((float) ($paymentSummary->cash_total ?? 0), 2),
            'card_total' => round((float) ($paymentSummary->card_total ?? 0), 2),
            'digital_total' => round((float) ($paymentSummary->digital_total ?? 0), 2),
            'refunded_count' => 0,
            'failed_count' => 0,
            'average_ticket' => $filteredCount > 0 ? round($totalReceived / $filteredCount, 2) : 0,
            'gross_sales' => $grossSales,
            'cost_of_goods' => $costOfGoods,
            'gross_profit_total' => $grossProfit,
            'gross_margin_percent' => $grossSales > 0 ? round(($grossProfit / $grossSales) * 100, 2) : 0,
            'units_sold' => (int) ($profitabilitySummary->units_sold ?? 0),
            'billed_transactions' => (int) ($profitabilitySummary->billed_transactions ?? 0),
        ];
    }
}
