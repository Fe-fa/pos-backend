<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Payment\ChargeBillingRequest;
use App\Models\Billing;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly PaymentService $service) {}

public function index(Request $request): JsonResponse
{
    if ($error = $this->authorizePermission('payments.view')) return $error;

    $perPage = max(1, min((int) ($request->per_page ?? 15), 100));
    $search  = trim((string) $request->search);

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
            'last_page'    => $payments->lastPage(),
            'per_page'     => $payments->perPage(),
            'total'        => $payments->total(),
            'from'         => $payments->firstItem(),
            'to'           => $payments->lastItem(),
        ],
        'summary' => [
            'filtered_count' => $summaryRows->count(),
            'total_received' => (float) $summaryRows->sum('amount_received'),
            'cash_total'     => (float) $cashTotal,
            'card_total'     => (float) $cardTotal,
            'digital_total'  => (float) $digitalTotal,
            'refunded_count' => (int) $summaryRows->where('status', 'refunded')->count(),
            'failed_count'   => (int) $summaryRows->where('status', 'failed')->count(),
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
        'data'    => $payment,
    ]);
}


    public function charge(ChargeBillingRequest $request, Billing $billing): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        return response()->json([
            'message' => 'Payment recorded successfully.',
            'data'    => $this->service->charge(
                $billing,
                $request->user(),
                $request->validated()
            ),
        ], 201);
    }
}