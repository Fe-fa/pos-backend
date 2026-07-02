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
            ->select([
                'supplier_id', 'supplier_name', 'store_id', 'is_active',
                'contact_person', 'phone', 'email', 'address', 'opening_balance',
            ])
            ->withSum(['grns as total_invoiced' => fn ($q) => $q->where('status', 'completed')], 'final_total')
            ->withSum(['grns as total_paid' => fn ($q) => $q->where('status', 'completed')], 'paid_amount');

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->store_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (int) $request->is_active);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('supplier_name', 'like', "%{$term}%")
                    ->orWhere('contact_person', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        $perPage = max(1, min((int) $request->get('per_page', 500), 500));
        $suppliers = $query->orderBy('supplier_name')->paginate($perPage);

        return response()->json([
            'data' => $suppliers->items(),
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
        $record = Supplier::query()
            ->withSum(['grns as total_invoiced' => fn ($q) => $q->where('status', 'completed')], 'final_total')
            ->withSum(['grns as total_paid' => fn ($q) => $q->where('status', 'completed')], 'paid_amount')
            ->findOrFail($supplier);

        return response()->json(['data' => $record]);
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
        ]);

        $supplier = Supplier::create($validated);

        return response()->json([
            'message' => 'Supplier created successfully.',
            'data' => $supplier,
        ], 201);
    }

    public function update(Request $request, $supplier): JsonResponse
    {
        $record = Supplier::findOrFail($supplier);

        $validated = $request->validate([
            'supplier_name' => 'sometimes|required|string|max:255',
            'store_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
        ]);

        $record->update($validated);

        return response()->json([
            'message' => 'Supplier updated successfully.',
            'data' => $record->fresh(),
        ]);
    }

    public function destroy($supplier): JsonResponse
    {
        Supplier::findOrFail($supplier)->delete();

        return response()->json(['message' => 'Supplier deleted successfully.']);
    }

    /** Full running ledger: opening balance, every posted GRN, every payment against it. */
    public function statement(Request $request, $supplier): JsonResponse
    {
        $record = Supplier::findOrFail($supplier);

        $grns = $record->postedGrns()
            ->select(['grn_id', 'grn_number', 'grn_date', 'invoice_number', 'final_total', 'paid_amount'])
            ->orderBy('grn_date')
            ->orderBy('grn_id')
            ->get();

        $running = (float) $record->opening_balance;
        $entries = [[
            'type' => 'opening_balance',
            'date' => null,
            'label' => 'Opening Balance',
            'debit' => round((float) $record->opening_balance, 2),
            'credit' => 0,
            'running_balance' => round($running, 2),
        ]];

        foreach ($grns as $grn) {
            $running += (float) $grn->final_total;
            $entries[] = [
                'type' => 'grn_charge',
                'date' => $grn->grn_date,
                'label' => $grn->grn_number ?: "GRN-{$grn->grn_id}",
                'reference' => $grn->invoice_number,
                'grn_id' => $grn->grn_id,
                'debit' => round((float) $grn->final_total, 2),
                'credit' => 0,
                'running_balance' => round($running, 2),
            ];

            if ((float) $grn->paid_amount > 0) {
                $running -= (float) $grn->paid_amount;
                $entries[] = [
                    'type' => 'payment',
                    'date' => $grn->grn_date,
                    'label' => 'Payment · ' . ($grn->grn_number ?: "GRN-{$grn->grn_id}"),
                    'grn_id' => $grn->grn_id,
                    'debit' => 0,
                    'credit' => round((float) $grn->paid_amount, 2),
                    'running_balance' => round($running, 2),
                ];
            }
        }

        return response()->json([
            'data' => [
                'supplier' => $record,
                'opening_balance' => round((float) $record->opening_balance, 2),
                'total_invoiced' => round((float) $grns->sum('final_total'), 2),
                'total_paid' => round((float) $grns->sum('paid_amount'), 2),
                'balance' => round($running, 2),
                'entries' => $entries,
            ],
        ]);
    }
}