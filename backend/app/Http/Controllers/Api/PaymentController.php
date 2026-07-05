<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ChargeBillingRequest;
use App\Http\Requests\Payment\ChargeCartRequest;
use App\Models\Billing;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly PaymentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.view')) return $error;

        $perPage = max(1, min((int) ($request->per_page ?? 15), 100));
        $search = trim((string) $request->search);

        $baseQuery = Payment::query()
            ->with([
                'billing:billing_id,uuid,invnumber,customer_id,store_id,user_id,total,vat_amount,balance_due,points_discount,notes,created_at',
                'billing.customer:customer_id,full_name,email,phone',
                'billing.store:store_id,store_name,currency',
                'billing.user:user_id,first_name,last_name,email',
            ])
            ->when($request->filled('store_id'), fn ($q) =>
                $q->whereHas('billing', fn ($b) => $b->where('billing.store_id', $request->store_id))
            )
            ->when($request->filled('status'), fn ($q) =>
                $q->whereHas('billing', fn ($b) => $b->where('billing.status', $request->status))
            )
            ->when($request->filled('payment_method'), fn ($q) =>
                $q->where('payments.payment_method', $request->payment_method)
            )
            ->when($request->filled('date_from'), fn ($q) =>
                $q->whereDate('payments.payment_date', '>=', $request->date_from)
            )
            ->when($request->filled('date_to'), fn ($q) =>
                $q->whereDate('payments.payment_date', '<=', $request->date_to)
            )
            ->when($request->filled('user_id'), fn ($q) =>
                $q->whereHas('billing', fn ($b) => $b->where('billing.user_id', $request->user_id))
            )
            ->when($request->filled('category_id'), fn ($q) =>
                $q->whereHas('billing.items.product', fn ($p) =>
                    $p->where('category_id', $request->category_id)
                )
            )
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('receiptnumber', 'like', "%{$search}%")
                        ->orWhereHas('billing', function ($b) use ($search) {
                            $b->where('invnumber', 'like', "%{$search}%")
                                ->orWhereHas('customer', function ($c) use ($search) {
                                    $c->where('full_name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%")
                                        ->orWhere('phone', 'like', "%{$search}%");
                                })
                                ->orWhereHas('user', function ($u) use ($search) {
                                    $u->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                                });
                        });
                });
            });

        $summaryRows = (clone $baseQuery)->get();

        $cashTotal = $summaryRows
            ->where('payment_method', 'cash')
            ->sum('amount_received');

        $cardTotal = $summaryRows
            ->filter(fn ($payment) => in_array(strtolower((string) $payment->payment_method), ['card', 'visa', 'mastercard', 'pos']))
            ->sum('amount_received');

        $digitalTotal = $summaryRows
            ->filter(fn ($payment) => in_array(strtolower((string) $payment->payment_method), ['mpesa', 'airtel_money', 'wallet', 'digital_wallet', 'bank']))
            ->sum('amount_received');

        $payments = (clone $baseQuery)
            ->orderByDesc('payment_id')
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
            'summary' => [
                'filtered_count' => $summaryRows->count(),
                'total_received' => (float) $summaryRows->sum('amount_received'),
                'cash_total' => (float) $cashTotal,
                'card_total' => (float) $cardTotal,
                'digital_total' => (float) $digitalTotal,
                'refunded_count' => (int) $summaryRows->where('status', 'refunded')->count(),
                'failed_count' => (int) $summaryRows->where('status', 'failed')->count(),
                'average_ticket' => $summaryRows->count() > 0
                    ? round((float) $summaryRows->sum('amount_received') / $summaryRows->count(), 2)
                    : 0,
            ],
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.view')) return $error;

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
        if ($error = $this->authorizePermission('payments.charge')) return $error;

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
        if ($error = $this->authorizePermission('payments.charge')) return $error;

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
}
