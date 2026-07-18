<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TransactionDeskService
{
    public function allowedStoreIds(User $user): array
    {
        if ($user->isAdmin()) {
            return Store::query()->pluck('store_id')->map(fn ($id) => (int) $id)->all();
        }

        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function authorizeStoreAccess(User $user, int $storeId): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (!in_array($storeId, $this->allowedStoreIds($user), true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'You are not allowed to access this store.',
            ], 403));
        }
    }

    public function resolveDateRange(array $filters): array
    {
        $preset = strtolower((string) ($filters['preset'] ?? 'today'));
        $today = Carbon::today();

        return match ($preset) {
            'yesterday' => [
                'preset' => 'yesterday',
                'start' => $today->copy()->subDay()->startOfDay(),
                'end' => $today->copy()->subDay()->endOfDay(),
            ],
            'this_week' => [
                'preset' => 'this_week',
                'start' => $today->copy()->startOfWeek()->startOfDay(),
                'end' => Carbon::now()->endOfDay(),
            ],
            'custom' => [
                'preset' => 'custom',
                'start' => Carbon::parse($filters['date_from'] ?? $today)->startOfDay(),
                'end' => Carbon::parse($filters['date_to'] ?? $today)->endOfDay(),
            ],
            default => [
                'preset' => 'today',
                'start' => $today->copy()->startOfDay(),
                'end' => Carbon::now()->endOfDay(),
            ],
        };
    }

    public function dashboard(User $user, array $filters): array
    {
        $requestedStoreId = !empty($filters['store_id']) ? (int) $filters['store_id'] : null;
        $storeIds = $requestedStoreId ? [$requestedStoreId] : $this->allowedStoreIds($user);

        if ($requestedStoreId) {
            $this->authorizeStoreAccess($user, $requestedStoreId);
        }

        $range = $this->resolveDateRange($filters);
        $perPage = max(10, min((int) ($filters['per_page'] ?? 20), 100));
        $page = max((int) ($filters['page'] ?? 1), 1);

        $ledgerBase = $this->ledgerUnion($storeIds, $range['start'], $range['end']);
        $filteredLedger = $this->applyLedgerFilters($ledgerBase, $filters);
        $ordered = DB::query()->fromSub($filteredLedger, 'desk_ledger')
            ->orderByDesc('occurred_at')
            ->orderByDesc('reference_no');

        $rows = $ordered->forPage($page, $perPage)->get();
        $total = DB::query()->fromSub($filteredLedger, 'desk_ledger')->count();

        $paginator = new LengthAwarePaginator($rows, $total, $perPage, $page);

        $summary = DB::query()->fromSub($this->ledgerUnion($storeIds, $range['start'], $range['end']), 'desk_summary')
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'INFLOW' THEN amount ELSE 0 END), 0) as total_inflows")
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'OUTGOING' AND category <> 'Safe Drop' THEN amount ELSE 0 END), 0) as total_outgoings")
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'OUTGOING' AND category = 'Safe Drop' THEN amount ELSE 0 END), 0) as safe_drops")
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'INFLOW' AND method = 'CASH' THEN amount ELSE 0 END), 0) as inflow_cash")
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'INFLOW' AND method = 'M-PESA' THEN amount ELSE 0 END), 0) as inflow_mpesa")
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'OUTGOING' AND method = 'CASH' AND category <> 'Safe Drop' THEN amount ELSE 0 END), 0) as outgoing_cash")
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'OUTGOING' AND method = 'M-PESA' THEN amount ELSE 0 END), 0) as outgoing_mpesa")
            ->selectRaw("COALESCE(SUM(CASE WHEN flow = 'OUTGOING' AND method = 'LOYALTY' THEN amount ELSE 0 END), 0) as loyalty_expense")
            ->first();

        $startingFloat = Schema::hasTable('cashier_shifts')
            ? (float) DB::table('cashier_shifts')
                ->whereIn('store_id', $storeIds)
                ->whereBetween('business_date', [
                    $range['start']->toDateString(),
                    $range['end']->toDateString(),
                ])
                ->sum('opening_balance')
            : 0.0;

        $vaultBalance = Schema::hasTable('finance_transactions')
            ? (float) DB::table('finance_transactions')
                ->whereIn('store_id', $storeIds)
                ->where('transaction_type', 'cash_drop')
                ->sum('amount')
            : 0.0;

        $categoryRows = DB::query()->fromSub($this->ledgerUnion($storeIds, $range['start'], $range['end']), 'desk_categories')
            ->select('category')
            ->selectRaw('COUNT(*) as transactions')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'transactions' => (int) $row->transactions,
                'total' => round((float) $row->total, 2),
            ])
            ->values()
            ->all();

        $openCashiers = Schema::hasTable('cashier_shifts')
            ? DB::table('cashier_shifts as cs')
                ->join('users as u', 'u.user_id', '=', 'cs.user_id')
                ->selectRaw("cs.user_id as cashier_user_id, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as cashier_name, cs.store_id")
                ->whereIn('cs.store_id', $storeIds)
                ->whereDate('cs.business_date', $range['end']->toDateString())
                ->where('cs.status', 'open')
                ->orderBy('cashier_name')
                ->get()
                ->map(fn ($row) => [
                    'cashier_user_id' => (int) $row->cashier_user_id,
                    'cashier_name' => trim((string) $row->cashier_name) ?: 'Cashier',
                    'store_id' => (int) $row->store_id,
                ])
                ->values()
                ->all()
            : [];

        $netAvailableFunds = round(
            $startingFloat
            + (float) ($summary->total_inflows ?? 0)
            - (float) ($summary->total_outgoings ?? 0)
            - (float) ($summary->safe_drops ?? 0),
            2
        );

        return [
            'filters' => [
                'store_ids' => $storeIds,
                'preset' => $range['preset'],
                'date_from' => $range['start']->toDateString(),
                'date_to' => $range['end']->toDateString(),
            ],
            'summary' => [
                'starting_float' => round($startingFloat, 2),
                'total_inflows' => round((float) ($summary->total_inflows ?? 0), 2),
                'total_outgoings' => round((float) ($summary->total_outgoings ?? 0), 2),
                'safe_drops' => round((float) ($summary->safe_drops ?? 0), 2),
                'net_available_funds' => $netAvailableFunds,
                'vault_balance' => round($vaultBalance, 2),
                'inflow_cash' => round((float) ($summary->inflow_cash ?? 0), 2),
                'inflow_mpesa' => round((float) ($summary->inflow_mpesa ?? 0), 2),
                'outgoing_cash' => round((float) ($summary->outgoing_cash ?? 0), 2),
                'outgoing_mpesa' => round((float) ($summary->outgoing_mpesa ?? 0), 2),
                'loyalty_expense' => round((float) ($summary->loyalty_expense ?? 0), 2),
                'system_total_in' => round((float) ($summary->total_inflows ?? 0), 2),
                'system_total_out' => round((float) ($summary->total_outgoings ?? 0), 2),
            ],
            'categories' => $categoryRows,
            'open_cashiers' => $openCashiers,
            'ledger' => [
                'data' => collect($paginator->items())->map(fn ($row) => [
                    'txn_date' => Carbon::parse($row->occurred_at)->format('Y-m-d H:i:s'),
                    'reference_no' => $row->reference_no,
                    'flow' => $row->flow,
                    'entity_name' => $row->entity_name,
                    'method' => $row->method,
                    'category' => $row->category,
                    'amount' => round((float) $row->amount, 2),
                    'signed_amount' => $row->flow === 'INFLOW' ? '+' . number_format((float) $row->amount, 2, '.', '') : '-' . number_format((float) $row->amount, 2, '.', ''),
                    'status' => $row->status_label,
                    'notes' => $row->notes,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                ])->values()->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ];
    }

    public function storeManualExpense(User $user, array $payload, CashierShiftService $cashierShiftService): FinanceTransaction
    {
        $storeId = (int) $payload['store_id'];
        $this->authorizeStoreAccess($user, $storeId);

        $cashierShiftId = null;
        $cashierUserId = !empty($payload['cashier_user_id']) ? (int) $payload['cashier_user_id'] : null;

        if ($payload['method'] === 'cash') {
            $summary = $cashierShiftService->buildUserDaySummary(
                $storeId,
                $cashierUserId ?: (int) $user->user_id,
                $payload['transaction_date'] ?? null,
            );

            if (($summary['cashier_shift_id'] ?? null) === null) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Open a cashier shift before posting a cash expense.',
                ], 422));
            }

            if ((float) ($summary['cash_in_till'] ?? 0) < (float) $payload['amount']) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Till balance is below the requested expense amount.',
                    'data' => [
                        'available_cash' => round((float) ($summary['cash_in_till'] ?? 0), 2),
                        'requested_amount' => round((float) $payload['amount'], 2),
                    ],
                ], 422));
            }

            $cashierShiftId = (int) $summary['cashier_shift_id'];
            $cashierUserId = (int) $summary['cashier_user_id'];
        }

        return FinanceTransaction::create([
            'uuid' => (string) Str::uuid(),
            'store_id' => $storeId,
            'user_id' => $cashierUserId ?: (int) $user->user_id,
            'cashier_shift_id' => $cashierShiftId,
            'transaction_type' => 'manual_expense',
            'flow' => 'outgoing',
            'method' => strtolower((string) $payload['method']),
            'category' => $this->labelCategory((string) $payload['category']),
            'entity_name' => trim((string) $payload['entity_name']),
            'reference_no' => 'EXP-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(5)),
            'amount' => round((float) $payload['amount'], 2),
            'transaction_date' => !empty($payload['transaction_date']) ? Carbon::parse($payload['transaction_date']) : now(),
            'status' => 'paid',
            'notes' => $payload['notes'] ?? null,
            'meta' => [
                'recorded_by_user_id' => (int) $user->user_id,
                'category_key' => (string) $payload['category'],
            ],
        ]);
    }

    public function labelCategory(string $value): string
    {
        return match ($value) {
            'petty_cash' => 'Petty Cash',
            'utilities' => 'Utilities',
            'transport' => 'Transport',
            'maintenance' => 'Maintenance',
            'rent' => 'Rent',
            'payroll_advance' => 'Payroll Advance',
            'bank_charges' => 'Bank Charges',
            'tax' => 'Tax',
            default => 'Other Expense',
        };
    }

    private function applyLedgerFilters(Builder $ledger, array $filters): Builder
    {
        return DB::query()->fromSub($ledger, 'desk_ledger_filtered')
            ->when(!empty($filters['flow']), fn ($q) => $q->where('flow', strtoupper((string) $filters['flow'])))
            ->when(!empty($filters['method']), fn ($q) => $q->where('method', strtoupper((string) $filters['method'])))
            ->when(!empty($filters['category']), fn ($q) => $q->where('category', $filters['category']))
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $term = trim((string) $filters['search']);
                $q->where(function ($sub) use ($term) {
                    $sub->where('reference_no', 'like', "%{$term}%")
                        ->orWhere('entity_name', 'like', "%{$term}%")
                        ->orWhere('category', 'like', "%{$term}%");
                });
            });
    }

    private function ledgerUnion(array $storeIds, Carbon $start, Carbon $end): Builder
    {
        $payments = DB::table('payments as p')
            ->join('billing as b', 'b.billing_id', '=', 'p.billing_id')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'b.customer_id')
            ->selectRaw("p.payment_date as occurred_at, COALESCE(NULLIF(TRIM(p.receiptnumber), ''), CONCAT('PAY-', p.payment_id)) as reference_no, 'INFLOW' as flow")
            ->selectRaw("CASE WHEN LOWER(p.payment_method) = 'mpesa' THEN 'M-PESA' ELSE 'CASH' END as method")
            ->selectRaw("CASE WHEN LOWER(p.payment_method) = 'mpesa' THEN 'Customer C2B' ELSE 'Retail Sales' END as category")
            ->selectRaw("COALESCE(NULLIF(TRIM(c.full_name), ''), 'Cash Sales') as entity_name")
            ->selectRaw('ABS(COALESCE(p.amount_received, 0)) as amount')
            ->selectRaw("'Received' as status_label")
            ->selectRaw("'payment' as source_type")
            ->selectRaw('p.payment_id as source_id')
            ->selectRaw('COALESCE(NULLIF(TRIM(b.notes), \'\'), NULL) as notes')
            ->whereIn('b.store_id', $storeIds)
            ->whereNull('b.deleted_at')
            ->whereBetween('p.payment_date', [$start, $end])
            ->whereIn(DB::raw('LOWER(p.payment_method)'), ['cash', 'mpesa']);

        $supplierPayouts = DB::table('grn_payments as gp')
            ->join('payment_vouchers as pv', 'pv.payment_voucher_id', '=', 'gp.payment_voucher_id')
            ->leftJoin('suppliers as s', 's.supplier_id', '=', 'pv.supplier_id')
            ->selectRaw("COALESCE(gp.paid_at, gp.created_at) as occurred_at, COALESCE(NULLIF(TRIM(gp.payment_number), ''), CONCAT('GRNPAY-', gp.grn_payment_id)) as reference_no, 'OUTGOING' as flow")
            ->selectRaw("CASE WHEN LOWER(gp.payment_method) = 'mpesa' THEN 'M-PESA' ELSE 'CASH' END as method")
            ->selectRaw("CASE WHEN LOWER(gp.payment_method) = 'mpesa' THEN 'Supplier B2B Transfer' ELSE 'Supplier Cash Payout' END as category")
            ->selectRaw("COALESCE(NULLIF(TRIM(s.supplier_name), ''), 'Supplier') as entity_name")
            ->selectRaw('ABS(COALESCE(gp.amount_paid, 0)) as amount')
            ->selectRaw("'Paid' as status_label")
            ->selectRaw("'grn_payment' as source_type")
            ->selectRaw('gp.grn_payment_id as source_id')
            ->selectRaw('gp.notes as notes')
            ->whereIn('gp.store_id', $storeIds)
            ->whereNull('gp.deleted_at')
            ->whereBetween(DB::raw('COALESCE(gp.paid_at, gp.created_at)'), [$start, $end])
            ->whereIn(DB::raw('LOWER(gp.payment_method)'), ['cash', 'mpesa']);

        $manual = DB::table('finance_transactions as ft')
            ->selectRaw("ft.transaction_date as occurred_at, COALESCE(NULLIF(TRIM(ft.reference_no), ''), CONCAT('FIN-', ft.finance_transaction_id)) as reference_no")
            ->selectRaw("CASE WHEN LOWER(ft.flow) = 'inflow' THEN 'INFLOW' ELSE 'OUTGOING' END as flow")
            ->selectRaw("CASE WHEN LOWER(ft.method) = 'mpesa' THEN 'M-PESA' ELSE 'CASH' END as method")
            ->selectRaw('ft.category as category')
            ->selectRaw('ft.entity_name as entity_name')
            ->selectRaw('ABS(COALESCE(ft.amount, 0)) as amount')
            ->selectRaw("CASE WHEN LOWER(ft.flow) = 'inflow' THEN 'Received' ELSE 'Paid' END as status_label")
            ->selectRaw("'finance_transaction' as source_type")
            ->selectRaw('ft.finance_transaction_id as source_id')
            ->selectRaw('ft.notes as notes')
            ->whereIn('ft.store_id', $storeIds)
            ->whereBetween('ft.transaction_date', [$start, $end]);

        $loyalty = DB::table('loyalty_transactions as lt')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'lt.customer_id')
            ->selectRaw("lt.created_at as occurred_at, CONCAT('LOY-', lt.id) as reference_no, 'OUTGOING' as flow")
            ->selectRaw("'LOYALTY' as method")
            ->selectRaw("'Promotional Expense' as category")
            ->selectRaw("COALESCE(NULLIF(TRIM(c.full_name), ''), 'Loyalty Reward') as entity_name")
            ->selectRaw('ABS(COALESCE(lt.amount_equivalent, 0)) as amount')
            ->selectRaw("'Paid' as status_label")
            ->selectRaw("'loyalty_redemption' as source_type")
            ->selectRaw('lt.id as source_id')
            ->selectRaw('lt.notes as notes')
            ->whereIn('lt.store_id', $storeIds)
            ->where('lt.transaction_type', 'redeemed')
            ->whereBetween('lt.created_at', [$start, $end]);

        return $payments->unionAll($supplierPayouts)->unionAll($manual)->unionAll($loyalty);
    }
}
