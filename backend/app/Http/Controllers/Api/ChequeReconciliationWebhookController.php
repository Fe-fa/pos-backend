<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChequeReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChequeReconciliationWebhookController extends Controller
{
    public function __construct(private readonly ChequeReconciliationService $service)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $expectedToken = (string) env('BANK_CHEQUE_WEBHOOK_TOKEN');
        $receivedToken = (string) $request->header('X-Cheque-Reconciliation-Token', '');

        if ($expectedToken !== '' && !hash_equals($expectedToken, $receivedToken)) {
            return response()->json(['message' => 'Unauthorized webhook token.'], 401);
        }

        $entries = $request->input('entries', []);
        if (!is_array($entries)) {
            return response()->json(['message' => 'Entries payload must be an array.'], 422);
        }

        return response()->json([
            'message' => 'Cheque reconciliation processed.',
            'data' => $this->service->reconcileEntries($entries),
        ]);
    }
}
