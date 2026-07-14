<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grn\ChargeGrnRequest;
use App\Http\Requests\Grn\CompleteGrnRequest;
use App\Http\Requests\Grn\StoreGrnItemRequest;
use App\Http\Requests\Grn\StoreGrnRequest;
use App\Http\Requests\Grn\UpdateGrnItemRequest;
use App\Http\Requests\Grn\UpdateGrnRequest;
use App\Models\Grn;
use App\Models\GrnItem;
use App\Services\GrnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrnController extends Controller
{
    public function __construct(private readonly GrnService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $grns = $this->service->list($request->user(), $request->all());

        return response()->json([
            'data' => $grns->items(),
            'meta' => [
                'current_page' => $grns->currentPage(),
                'last_page' => $grns->lastPage(),
                'per_page' => $grns->perPage(),
                'total' => $grns->total(),
                'from' => $grns->firstItem(),
                'to' => $grns->lastItem(),
            ],
        ]);
    }

    public function store(StoreGrnRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'GRN draft saved successfully.',
            'data' => $this->service->createDraft($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, Grn $grn): JsonResponse
    {
        return response()->json([
            'data' => $this->service->show($request->user(), $grn),
        ]);
    }

    public function update(UpdateGrnRequest $request, Grn $grn): JsonResponse
    {
        return response()->json([
            'message' => 'GRN updated successfully.',
            'data' => $this->service->updateDraft($request->user(), $grn, $request->validated()),
        ]);
    }

    public function addItem(StoreGrnItemRequest $request, Grn $grn): JsonResponse
    {
        return response()->json([
            'message' => 'GRN item added successfully.',
            'data' => $this->service->addItem($request->user(), $grn, $request->validated()),
        ], 201);
    }

    public function updateItem(UpdateGrnItemRequest $request, Grn $grn, GrnItem $grnItem): JsonResponse
    {
        return response()->json([
            'message' => 'GRN item updated successfully.',
            'data' => $this->service->updateItem($request->user(), $grn, $grnItem, $request->validated()),
        ]);
    }

    public function deleteItem(Request $request, Grn $grn, GrnItem $grnItem): JsonResponse
    {
        $this->service->deleteItem($request->user(), $grn, $grnItem);
        return response()->json(['message' => 'GRN item deleted successfully.']);
    }

    public function charge(ChargeGrnRequest $request, Grn $grn): JsonResponse
    {
        return response()->json([
            'message' => 'GRN payment recorded successfully against the payment voucher.',
            'data' => $this->service->charge($request->user(), $grn, $request->validated()),
        ]);
    }

    public function complete(CompleteGrnRequest $request, Grn $grn): JsonResponse
    {
        return response()->json([
            'message' => 'GRN completed successfully.',
            'data' => $this->service->complete($request->user(), $grn, $request->validated()),
        ]);
    }

    public function destroy(Request $request, Grn $grn): JsonResponse
    {
        $this->service->deleteDraft($request->user(), $grn);
        return response()->json(['message' => 'GRN deleted successfully.']);
    }
}
