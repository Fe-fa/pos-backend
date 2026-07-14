<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()
            ->withCount([
                'purchaseOrders as open_purchase_orders_count' => fn ($q) => $q->whereIn('status', ['ordered', 'partially_received']),
            ]);

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->store_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (int) $request->is_active);
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->where(function ($q) use ($term) {
                $q->where('supplier_name', 'like', "%{$term}%")
                    ->orWhere('contact_person', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        $perPage = max(1, min((int) $request->get('per_page', 200), 500));
        $suppliers = $query->orderBy('supplier_name')->paginate($perPage);

        return response()->json([
            'data' => collect($suppliers->items())->map(function (Supplier $supplier) {
                return [
                    ...$supplier->toArray(),
                    'current_balance' => round((float) $supplier->balance, 2),
                    'outstanding_balance' => round((float) $supplier->balance, 2),
                ];
            })->values(),
            'meta' => [
                'current_page' => $suppliers->currentPage(),
                'last_page' => $suppliers->lastPage(),
                'per_page' => $suppliers->perPage(),
                'total' => $suppliers->total(),
            ],
        ]);
    }
    public function show($supplier): JsonResponse
    {
        $record = Supplier::query()->with('ledgerEntries')->findOrFail($supplier);
        $record->refreshCurrentBalance();

        return response()->json(['data' => $record->fresh()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_name' => 'required|string|max:255',
            'store_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'credit_days' => 'nullable|integer|min:0|max:365',
        ]);

        $supplier = Supplier::create([
            ...$validated,
            'current_balance' => round((float) ($validated['opening_balance'] ?? 0), 2),
            'outstanding_balance' => round((float) ($validated['opening_balance'] ?? 0), 2),
        ]);

        return response()->json([
            'message' => 'Supplier created successfully.',
            'data' => $supplier->fresh(),
        ], 201);
    }

    public function update(Request $request, $supplier): JsonResponse
    {
        $record = Supplier::query()->findOrFail($supplier);

        $validated = $request->validate([
            'supplier_name' => 'sometimes|required|string|max:255',
            'store_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'credit_days' => 'nullable|integer|min:0|max:365',
        ]);

        $record->update($validated);
        $record->refreshCurrentBalance();

        return response()->json([
            'message' => 'Supplier updated successfully.',
            'data' => $record->fresh(),
        ]);
    }

    public function destroy($supplier): JsonResponse
    {
        Supplier::query()->findOrFail($supplier)->delete();
        return response()->json(['message' => 'Supplier deleted successfully.']);
    }

    public function statement(Request $request, $supplier): JsonResponse
    {
        $record = Supplier::query()
            ->with([
                'ledgerEntries.user',
                'ledgerEntries.grn',
                'ledgerEntries.purchaseOrder',
                'grns' => fn ($q) => $q->where('status', 'completed')->latest('grn_date'),
                'grns.items',
                'grns.payments',
            ])
            ->findOrFail($supplier);
        $record->refreshCurrentBalance();
        $running = (float) $record->opening_balance;

        $entries = collect([[
            'type' => 'opening_balance',
            'date' => null,
            'label' => 'Opening Balance',
            'debit' => round((float) $record->opening_balance, 2),
            'credit' => 0,
            'reference' => null,
            'running_balance' => round($running, 2),
        ]]);

        foreach ($record->ledgerEntries->sortBy('entry_date') as $entry) {
            $amount = round((float) $entry->amount, 2);
            $running += $entry->direction === 'debit' ? $amount : -$amount;

            $entries->push([
                'type' => $entry->entry_type,
                'date' => $entry->entry_date,
                'label' => $entry->description,
                'reference' => $entry->reference_number,
                'debit' => $entry->direction === 'debit' ? $amount : 0,
                'credit' => $entry->direction === 'credit' ? $amount : 0,
                'running_balance' => round($running, 2),
            ]);
        }

        $completedGrns = $record->grns ?? collect();
        $receivedQty = $completedGrns->sum(function ($grn) {
            return collect($grn->items ?? [])->sum(function ($item) {
                return (int) ($item->quantity_accepted ?? $item->qty_received ?? 0) + (int) ($item->free_qty ?? 0);
            });
        });

        $documents = $completedGrns->map(function ($grn) {
            $payments = collect($grn->payments ?? [])->sortByDesc('paid_at')->values();
            $latestPayment = $payments->first();
            return [
                'grn_id' => $grn->grn_id,
                'grn_number' => $grn->grn_number,
                'invoice_number' => $grn->invoice_number,
                'grn_date' => $grn->grn_date,
                'final_total' => round((float) $grn->final_total, 2),
                'balance_due' => round((float) $grn->balance_due, 2),
                'release_to_inventory' => (bool) $grn->release_to_inventory,
                'stock_applied_at' => $grn->stock_applied_at,
                'received_qty' => collect($grn->items ?? [])->sum(function ($item) {
                    return (int) ($item->quantity_accepted ?? $item->qty_received ?? 0) + (int) ($item->free_qty ?? 0);
                }),
                'receipt_count' => $payments->count(),
                'has_receipt' => $payments->isNotEmpty(),
                'latest_payment_number' => $latestPayment?->payment_number,
                'latest_receipt_code' => $latestPayment?->mpesa_code,
                'latest_payment_method' => $latestPayment?->payment_method,
            ];
        })->values();

        $recentDocuments = $documents->take(12)->values();

        return response()->json([
            'data' => [
                'supplier' => $record->fresh(),
                'opening_balance' => round((float) $record->opening_balance, 2),
                'total_invoiced' => round((float) $record->ledgerEntries->where('direction', 'debit')->sum('amount'), 2),
                'total_paid' => round((float) $record->ledgerEntries->where('direction', 'credit')->sum('amount'), 2),
                'balance' => round((float) $record->balance, 2),
                'completed_grns_count' => $completedGrns->count(),
                'received_qty_total' => (int) $receivedQty,
                'goods_bought_total_value' => round((float) $completedGrns->sum('final_total'), 2),
                'last_grn_number' => optional($completedGrns->first())->grn_number,
                'recent_documents' => $recentDocuments,
                'documents' => $documents,
                'entries' => $entries->values(),
            ],
        ]);
    }
}
