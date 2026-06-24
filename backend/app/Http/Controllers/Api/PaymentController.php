<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Payment\ChargeBillingRequest;
use App\Models\Billing;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly PaymentService $service) {}

    public function index(Request $request): JsonResponse
{
    $perPage = max(1, min((int) ($request->per_page ?? 15), 100));

    $query = Payment::query()
        ->with([
            'billing:billing_id,uuid,invnumber,customer_id,store_id,user_id',
            'billing.customer:customer_id,full_name,email,phone',
            'billing.store:store_id,store_name,currency',
            'billing.user:user_id,first_name,last_name',
        ])
        ->when($request->filled('store_id'), fn($q) =>
            $q->whereHas('billing', fn($b) => $b->where('store_id', $request->store_id))
        )
    ->when($request->filled('status'), fn($q) =>
    $q->where('status', $request->status)
        )
        ->when($request->filled('payment_method'), fn($q) =>
            $q->where('payment_method', $request->payment_method)
        )
        ->when($request->filled('search'), fn($q) =>
            $q->where(function ($sub) use ($request) {
                $sub->where('receiptnumber', 'like', "%{$request->search}%")
                    ->orWhereHas('billing', fn($b) =>
                        $b->where('invnumber', 'like', "%{$request->search}%")
                          ->orWhereHas('customer', fn($c) =>
                              $c->where('full_name', 'like', "%{$request->search}%")
                          )
                    );
            })
        )
        ->orderByDesc('payment_id');

    $payments = $query->paginate($perPage);

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
    ]);
}

public function show(Payment $payment): JsonResponse
{
    $payment->load([
        'billing.customer',
        'billing.store',
        'billing.user',
    ]);

    return response()->json([
        'message' => 'Payment retrieved successfully.',
        'data'    => $payment,
    ]);
}

    public function charge(ChargeBillingRequest $request, Billing $billing): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.manage')) return $error;

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