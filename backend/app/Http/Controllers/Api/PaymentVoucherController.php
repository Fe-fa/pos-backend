<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentVoucher\StorePaymentVoucherRequest;
use App\Http\Requests\PaymentVoucher\UpdatePaymentVoucherRequest;
use App\Models\PaymentVoucher;
use App\Services\PaymentVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentVoucherController extends Controller
{
    public function __construct(private readonly PaymentVoucherService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = max(1, min((int) ($request->per_page ?? 15), 100));

        $query = PaymentVoucher::query()
            ->with($this->service->detailRelations())
            ->orderByDesc('payment_voucher_id');

        $this->service->scopeAccessible($query, $user)
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', (int) $request->store_id))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', (int) $request->supplier_id))
            ->when($request->filled('grn_id'), fn ($q) => $q->where('grn_id', (int) $request->grn_id))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('payment_method') && $request->payment_method !== 'all', fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('voucher_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('voucher_date', '<=', $request->date_to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->search);
                $q->where(function ($sub) use ($term) {
                    $sub->where('voucher_number', 'like', "%{$term}%")
                        ->orWhere('receipt_number', 'like', "%{$term}%")
                        ->orWhere('invoice_number', 'like', "%{$term}%")
                        ->orWhere('delivery_note_no', 'like', "%{$term}%")
                        ->orWhere('payee_name', 'like', "%{$term}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$term}%"))
                        ->orWhereHas('grn', fn ($gq) => $gq->where('grn_number', 'like', "%{$term}%"))
                        ->orWhereHas('purchaseOrder', fn ($pq) => $pq->where('po_number', 'like', "%{$term}%"));
                });
            });

        $vouchers = $query->paginate($perPage);

        $summary = DB::query()
            ->fromSub((clone $query)->select([
                'payment_voucher_id',
                'amount',
                'paid_amount',
                'balance_due',
                'status',
            ]), 'v')
            ->selectRaw('COUNT(*) as total_vouchers')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_vouchered')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as total_paid')
            ->selectRaw('COALESCE(SUM(balance_due), 0) as total_outstanding')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) as draft_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('pending_approval', 'override_required') THEN 1 ELSE 0 END), 0) as pending_approval_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'authorized' THEN 1 ELSE 0 END), 0) as authorized_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('partial', 'partially_paid', 'processing', 'pending_settlement') THEN 1 ELSE 0 END), 0) as partial_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) as paid_count")
            ->first();

        return response()->json([
            'data' => $this->service->presentMany($vouchers->items()),
            'meta' => [
                'current_page' => $vouchers->currentPage(),
                'last_page' => $vouchers->lastPage(),
                'per_page' => $vouchers->perPage(),
                'total' => $vouchers->total(),
                'from' => $vouchers->firstItem(),
                'to' => $vouchers->lastItem(),
            ],
            'summary' => [
                'total_vouchers' => (int) ($summary->total_vouchers ?? 0),
                'total_vouchered' => round((float) ($summary->total_vouchered ?? 0), 2),
                'total_paid' => round((float) ($summary->total_paid ?? 0), 2),
                'total_outstanding' => round((float) ($summary->total_outstanding ?? 0), 2),
                'draft_count' => (int) ($summary->draft_count ?? 0),
                'pending_approval_count' => (int) ($summary->pending_approval_count ?? 0),
                'authorized_count' => (int) ($summary->authorized_count ?? 0),
                'partial_count' => (int) ($summary->partial_count ?? 0),
                'paid_count' => (int) ($summary->paid_count ?? 0),
            ],
        ]);
    }

    public function store(StorePaymentVoucherRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Payment voucher submitted successfully.',
            'data' => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, PaymentVoucher $paymentVoucher): JsonResponse
    {
        return response()->json([
            'data' => $this->service->show($request->user(), $paymentVoucher),
        ]);
    }

    public function update(UpdatePaymentVoucherRequest $request, PaymentVoucher $paymentVoucher): JsonResponse
    {
        return response()->json([
            'message' => 'Payment voucher updated successfully.',
            'data' => $this->service->update($request->user(), $paymentVoucher, $request->validated()),
        ]);
    }

    public function generateReceipt(Request $request, PaymentVoucher $paymentVoucher): JsonResponse
    {
        return response()->json([
            'message' => 'Final payment receipt generated successfully.',
            'data' => $this->service->generateReceipt($request->user(), $paymentVoucher),
        ]);
    }
}
