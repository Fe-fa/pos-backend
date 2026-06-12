<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Payment\ChargeBillingRequest;
use App\Models\Billing;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly PaymentService $service) {}

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