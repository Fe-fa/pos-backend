<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreBillingRequest;
use App\Http\Requests\Billing\UpdateBillingRequest;
use App\Models\Billing;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private readonly BillingService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Billings retrieved successfully.',
            'data' => $this->service->paginate(
                $request->user(),
                $request->only('store_id', 'status', 'is_draft', 'per_page', 'with_trashed', 'only_trashed')
            ),
        ]);
    }

    public function store(StoreBillingRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Draft billing created successfully.',
            'data' => $this->service->createDraft($request->user(), $request->validated()),
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $billing = Billing::find($id);

        if (!$billing) {
            return response()->json([
                'message' => "Billing record #{$id} was not found in our system.",
                'data' => null
            ], 404);
        }

        return response()->json([
            'message' => 'Billing retrieved successfully.',
            'data' => $this->service->show($billing),
        ]);
    }

    public function update(UpdateBillingRequest $request, $id): JsonResponse
    {
        $billing = Billing::find($id);

        if (!$billing) {
            return response()->json([
                'message' => "Update failed: Billing record #{$id} does not exist.",
                'debug_info' => 'Check if the record was deleted or if the database was refreshed.'
            ], 404);
        }

        return response()->json([
            'message' => 'Billing updated successfully.',
            'data' => $this->service->updateHeader($billing, $request->validated()),
        ]);
    }

    public function destroy(Billing $billing): JsonResponse
    {
        $this->service->destroy($billing);

        return response()->json([
            'message' => 'Billing deleted successfully.',
        ]);
    }

    public function restore(Request $request, $id): JsonResponse
    {
        return response()->json([
            'message' => 'Billing restored successfully.',
            'data' => $this->service->restore($id, $request->user()),
        ]);
    }
}
