<?php

namespace App\Services;

use App\Models\Grn;
use App\Models\GrnPayment;
use App\Models\MpesaTransaction;
use App\Models\PaymentVoucher;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\GrnService;

class PaymentVoucherService
{
    private const STATUS_DRAFT = 'draft';
    private const STATUS_PENDING_APPROVAL = 'pending_approval';
    private const STATUS_OVERRIDE_REQUIRED = 'override_required';
    private const STATUS_AUTHORIZED = 'authorized';
    private const STATUS_PARTIALLY_PAID = 'partially_paid';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_PAID = 'paid';
    private const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly ?CashierShiftService $cashierShiftService = null,
        private readonly ?GrnService $grnService = null,
    ) {
    }

    public function allowedStoreIds(User $user): Collection
    {
        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function authorizeStoreAccess(User $user, int|string|null $storeId): void
    {
        if (!$storeId || $user->isAdmin()) {
            return;
        }

        if (!$this->allowedStoreIds($user)->contains((int) $storeId)) {
            throw new HttpResponseException(response()->json([
                'message' => 'You are not allowed to access this store.',
            ], 403));
        }
    }

    public function scopeAccessible(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('store_id', $this->allowedStoreIds($user));
    }

    public function create(User $user, array $data): PaymentVoucher
    {
        $this->authorizeStoreAccess($user, $data['store_id'] ?? null);

        return DB::transaction(function () use ($user, $data) {
            $grn = Grn::query()
                ->with(['items.purchaseOrderItem', 'supplier', 'purchaseOrder', 'store'])
                ->lockForUpdate()
                ->findOrFail((int) $data['grn_id']);

            $this->authorizeStoreAccess($user, $grn->store_id);

            if ((int) $grn->store_id !== (int) $data['store_id']) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The selected GRN does not belong to the supplied store.',
                ], 422));
            }

            if ((int) $grn->supplier_id !== (int) $data['supplier_id']) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The selected GRN does not belong to the supplied supplier.',
                ], 422));
            }

            if ($grn->status !== 'completed') {
                throw new HttpResponseException(response()->json([
                    'message' => 'Complete the GRN before preparing a payment voucher.',
                ], 422));
            }

            if ((float) $grn->balance_due <= 0) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This GRN has no outstanding supplier balance.',
                ], 422));
            }

            $openVoucher = PaymentVoucher::query()
                ->where('grn_id', $grn->grn_id)
                ->whereNotIn('status', [self::STATUS_PAID, self::STATUS_CANCELLED])
                ->first();

            if ($openVoucher) {
                throw new HttpResponseException(response()->json([
                    'message' => 'An active payment voucher already exists for this GRN.',
                    'data' => $this->present($openVoucher->load($this->detailRelations())),
                ], 422));
            }

            $supplier = $grn->supplier ?: Supplier::query()->findOrFail($grn->supplier_id);
            $lineItems = $this->buildLineItemsFromGrn($grn);
            $amount = round((float) $grn->balance_due, 2);
            $requestedStatus = $this->normalizeStatus($data['status'] ?? self::STATUS_PENDING_APPROVAL);
            $matchSummary = $this->buildThreeWayMatchSummary($grn);

            $status = match ($requestedStatus) {
                self::STATUS_DRAFT => self::STATUS_DRAFT,
                default => ($matchSummary['status'] ?? 'variance') === 'matched'
                    ? self::STATUS_PENDING_APPROVAL
                    : self::STATUS_OVERRIDE_REQUIRED,
            };

            $voucher = PaymentVoucher::create([
                'store_id' => $grn->store_id,
                'supplier_id' => $grn->supplier_id,
                'grn_id' => $grn->grn_id,
                'purchase_order_id' => $grn->purchase_order_id,
                'prepared_by_user_id' => $user->user_id,
                'approved_by_user_id' => null,
                'voucher_number' => null,
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'delivery_note_no' => $data['delivery_note_no'] ?? null,
                'invoice_number' => $data['invoice_number'] ?? $grn->invoice_number,
                'payee_name' => $data['payee_name'] ?? $supplier->supplier_name,
                'payee_address' => $data['payee_address'] ?? $supplier->address,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_account' => $data['payment_account'] ?? null,
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_date' => $data['cheque_date'] ?? null,
                'amount' => $amount,
                'paid_amount' => 0,
                'balance_due' => $amount,
                'status' => $status,
                'authorized_by' => null,
                'authorized_signature' => null,
                'authorized_date' => null,
                'line_items' => $lineItems,
                'notes' => $data['notes'] ?? null,
            ]);

            $voucher->update([
                'voucher_number' => 'PV-' . str_pad((string) $voucher->payment_voucher_id, 6, '0', STR_PAD_LEFT),
            ]);

            return $this->present($voucher->fresh()->load($this->detailRelations()));
        });
    }

    public function update(User $user, PaymentVoucher $voucher, array $data): PaymentVoucher
    {
        $this->authorizeStoreAccess($user, $voucher->store_id);

        return DB::transaction(function () use ($user, $voucher, $data) {
            $record = PaymentVoucher::query()
                ->with($this->detailRelations())
                ->lockForUpdate()
                ->findOrFail($voucher->payment_voucher_id);

            $this->authorizeStoreAccess($user, $record->store_id);
            $currentStatus = $this->normalizeStatus($record->status);
            $requestedStatus = array_key_exists('status', $data)
                ? $this->normalizeStatus($data['status'])
                : $currentStatus;

            if (in_array($currentStatus, [self::STATUS_PAID, self::STATUS_CANCELLED], true)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Paid or cancelled vouchers cannot be edited.',
                ], 422));
            }

            if ($currentStatus === self::STATUS_PROCESSING) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This voucher is processing an M-Pesa payout and is temporarily locked.',
                ], 422));
            }

            if (in_array($currentStatus, [self::STATUS_AUTHORIZED, self::STATUS_PARTIALLY_PAID], true)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Authorized or partially paid vouchers are read-only. Use the payment window to continue settlement.',
                ], 422));
            }

            $summary = $this->buildThreeWayMatchSummary($record->grn, $record);
            $requiresOverride = ($summary['status'] ?? 'variance') !== 'matched';
            $overrideNote = trim((string) ($data['override_note'] ?? ''));

            if ($currentStatus === self::STATUS_PENDING_APPROVAL) {
                if (!in_array($requestedStatus, [self::STATUS_AUTHORIZED, self::STATUS_CANCELLED], true)) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Pending approval vouchers are locked. Approve or cancel the document from the approval desk.',
                    ], 422));
                }

                if ($requestedStatus === self::STATUS_AUTHORIZED) {
                    if ($requiresOverride) {
                        $record->update(['status' => self::STATUS_OVERRIDE_REQUIRED]);

                        throw new HttpResponseException(response()->json([
                            'message' => '3-way match variance detected. Capture an override reason, then use Authorize & Save Override.',
                            'data' => [
                                'match_summary' => $summary,
                                'status' => self::STATUS_OVERRIDE_REQUIRED,
                            ],
                        ], 422));
                    }

                    $record->fill([
                        'status' => self::STATUS_AUTHORIZED,
                        'authorized_by' => $data['authorized_by'] ?? $record->authorized_by ?? $this->displayName($user),
                        'authorized_signature' => $data['authorized_signature'] ?? $record->authorized_signature ?? $this->displayName($user),
                        'authorized_date' => $data['authorized_date'] ?? $record->authorized_date ?? now()->toDateString(),
                        'notes' => array_key_exists('notes', $data) ? $data['notes'] : $record->notes,
                        'approved_by_user_id' => $user->user_id,
                    ]);
                    $record->save();

                    return $this->present($record->fresh()->load($this->detailRelations()));
                }

                $record->update([
                    'status' => self::STATUS_CANCELLED,
                    'notes' => array_key_exists('notes', $data) ? $data['notes'] : $record->notes,
                ]);

                return $this->present($record->fresh()->load($this->detailRelations()));
            }

            if ($currentStatus === self::STATUS_OVERRIDE_REQUIRED) {
                if (!in_array($requestedStatus, [self::STATUS_AUTHORIZED, self::STATUS_CANCELLED], true)) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'OVERRIDE_REQUIRED vouchers are locked. Only Authorize & Save Override or Cancel is allowed.',
                    ], 422));
                }

                if ($requestedStatus === self::STATUS_AUTHORIZED) {
                    if (!$this->canApproveVariance($user)) {
                        throw new HttpResponseException(response()->json([
                            'message' => 'Only an admin or manager can authorize a 3-way match override.',
                            'data' => [
                                'match_summary' => $summary,
                            ],
                        ], 403));
                    }

                    if ($overrideNote === '') {
                        throw new HttpResponseException(response()->json([
                            'message' => 'Provide an override reason before authorizing this voucher.',
                            'data' => [
                                'match_summary' => $summary,
                            ],
                        ], 422));
                    }

                    $notes = array_key_exists('notes', $data) ? $data['notes'] : $record->notes;
                    $record->fill([
                        'status' => self::STATUS_AUTHORIZED,
                        'authorized_by' => $data['authorized_by'] ?? $record->authorized_by ?? $this->displayName($user),
                        'authorized_signature' => $data['authorized_signature'] ?? $record->authorized_signature ?? $this->displayName($user),
                        'authorized_date' => $data['authorized_date'] ?? $record->authorized_date ?? now()->toDateString(),
                        'notes' => $this->appendOverrideAuditNote($notes, $user, $overrideNote, $summary),
                        'approved_by_user_id' => $user->user_id,
                    ]);
                    $record->save();

                    return $this->present($record->fresh()->load($this->detailRelations()));
                }

                $record->update([
                    'status' => self::STATUS_CANCELLED,
                    'notes' => array_key_exists('notes', $data) ? $data['notes'] : $record->notes,
                ]);

                return $this->present($record->fresh()->load($this->detailRelations()));
            }

            if (!in_array($requestedStatus, [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_CANCELLED], true)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Draft vouchers can only be saved as draft, submitted for approval, or cancelled.',
                ], 422));
            }

            $nextStatus = $requestedStatus;
            if ($requestedStatus === self::STATUS_PENDING_APPROVAL) {
                $nextStatus = $requiresOverride ? self::STATUS_OVERRIDE_REQUIRED : self::STATUS_PENDING_APPROVAL;
            }

            $record->fill([
                'voucher_date' => $data['voucher_date'] ?? $record->voucher_date,
                'delivery_note_no' => array_key_exists('delivery_note_no', $data) ? $data['delivery_note_no'] : $record->delivery_note_no,
                'invoice_number' => array_key_exists('invoice_number', $data) ? $data['invoice_number'] : $record->invoice_number,
                'payee_name' => $data['payee_name'] ?? $record->payee_name,
                'payee_address' => array_key_exists('payee_address', $data) ? $data['payee_address'] : $record->payee_address,
                'payment_method' => $data['payment_method'] ?? $record->payment_method,
                'payment_account' => array_key_exists('payment_account', $data) ? $data['payment_account'] : $record->payment_account,
                'cheque_no' => array_key_exists('cheque_no', $data) ? $data['cheque_no'] : $record->cheque_no,
                'cheque_date' => array_key_exists('cheque_date', $data) ? $data['cheque_date'] : $record->cheque_date,
                'authorized_by' => array_key_exists('authorized_by', $data) ? $data['authorized_by'] : $record->authorized_by,
                'authorized_signature' => array_key_exists('authorized_signature', $data) ? $data['authorized_signature'] : $record->authorized_signature,
                'authorized_date' => array_key_exists('authorized_date', $data) ? $data['authorized_date'] : $record->authorized_date,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $record->notes,
                'status' => $nextStatus,
            ]);
            $record->save();

            return $this->present($record->fresh()->load($this->detailRelations()));
        });
    }

    public function show(User $user, PaymentVoucher $voucher): PaymentVoucher
    {
        $this->authorizeStoreAccess($user, $voucher->store_id);
        return $this->present($voucher->load($this->detailRelations()));
    }

    public function processManualCashPayout(User $user, PaymentVoucher $voucher, array $data): array
    {
        $this->authorizeStoreAccess($user, $voucher->store_id);

        return DB::transaction(function () use ($user, $voucher, $data) {
            $record = PaymentVoucher::query()
                ->with($this->detailRelations())
                ->lockForUpdate()
                ->findOrFail($voucher->payment_voucher_id);

            $this->authorizeStoreAccess($user, $record->store_id);
            $this->assertPayoutAllowed($record);
            $this->assertCashDrawerCanCover($user, $record, (float) $data['amount']);

            $record = $this->recalculateVoucherFinancials($record);
            $grn = $this->syncLinkedGrnTotals($record->grn_id);
            $remainingBalance = min(
                $this->remainingBalanceForVoucher($record),
                round((float) ($grn?->balance_due ?? $record->balance_due ?? 0), 2)
            );
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Amount to disburse must be greater than zero.',
                ], 422));
            }

            if ($amount > $remainingBalance) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Requested amount exceeds outstanding voucher balance.',
                ], 422));
            }

            $payment = GrnPayment::create([
                'grn_id' => $record->grn_id,
                'store_id' => $record->store_id,
                'user_id' => $user->user_id,
                'payment_voucher_id' => $record->payment_voucher_id,
                'payment_number' => null,
                'payment_voucher_number' => $record->voucher_number,
                'payment_method' => 'cash',
                'status' => 'completed',
                'amount_paid' => $amount,
                'amount_received' => $amount,
                'amount_tendered' => $amount,
                'change_returned' => 0,
                'notes' => trim((string) ($data['remarks'] ?? 'Manual cash supplier payout recorded from the Payment Voucher desk.')),
                'paid_at' => now(),
            ]);

            if (!$payment->payment_number) {
                $payment->update([
                    'payment_number' => 'GRNPAY-' . str_pad((string) $payment->grn_payment_id, 6, '0', STR_PAD_LEFT),
                ]);
            }

            $payment = $this->generatePaymentSlip($record, $payment->fresh(), $user);
            $this->recordSupplierPaymentLedger($record->grn, $record, $payment, $user, 'cash');
            $this->refreshSupplierBalance($record->supplier_id);
            $record = $this->syncVoucherAndGrnAfterSettlement($record, $user);

            return [
                'voucher' => $this->present($record),
                'payment' => $payment->fresh(),
            ];
        });
    }

    public function recordGatewaySettlement(MpesaTransaction $txn): ?GrnPayment
    {
        if (!$txn->payment_voucher_id || !$txn->grn_id) {
            return null;
        }

        return DB::transaction(function () use ($txn) {
            $txn = MpesaTransaction::query()->lockForUpdate()->findOrFail($txn->mpesa_transaction_id);
            if ($txn->payment_id) {
                return GrnPayment::query()->find($txn->payment_id);
            }

            $voucher = PaymentVoucher::query()
                ->with(['grn', 'supplier', 'store', 'preparedBy'])
                ->lockForUpdate()
                ->findOrFail($txn->payment_voucher_id);

            $voucher = $this->recalculateVoucherFinancials($voucher);
            $grn = $this->syncLinkedGrnTotals($txn->grn_id) ?: ($voucher->grn ?: Grn::query()->lockForUpdate()->findOrFail($txn->grn_id));
            $remainingBalance = min(
                $this->remainingBalanceForVoucher($voucher),
                round((float) ($grn->balance_due ?? 0), 2)
            );
            if ($remainingBalance <= 0) {
                return null;
            }

            $user = $txn->user()->first() ?: $voucher->preparedBy()->first() ?: $grn->user()->first();
            if (!$user) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Unable to resolve the operator for this supplier payout.',
                ], 422));
            }

            $amountReceived = round((float) $txn->amount, 2);
            $amountPaid = round(min($amountReceived, $remainingBalance), 2);
            if ($amountPaid <= 0) {
                return null;
            }

            $payment = GrnPayment::create([
                'grn_id' => $voucher->grn_id,
                'store_id' => $voucher->store_id,
                'user_id' => $user->user_id,
                'payment_voucher_id' => $voucher->payment_voucher_id,
                'payment_number' => null,
                'payment_voucher_number' => $voucher->voucher_number,
                'payment_method' => 'mpesa',
                'status' => 'completed',
                'amount_paid' => $amountPaid,
                'amount_received' => $amountReceived,
                'amount_tendered' => $amountReceived,
                'change_returned' => 0,
                'mpesa_phone' => $txn->phone_number,
                'mpesa_code' => $txn->mpesa_receipt,
                'bank_reference' => $txn->conversation_id,
                'notes' => 'Auto-posted from supplier M-Pesa payout callback',
                'paid_at' => $txn->transaction_date ?? now(),
            ]);

            if (!$payment->payment_number) {
                $payment->update([
                    'payment_number' => 'GRNPAY-' . str_pad((string) $payment->grn_payment_id, 6, '0', STR_PAD_LEFT),
                ]);
            }

            $payment = $this->generatePaymentSlip($voucher, $payment->fresh(), $user);
            $this->recordSupplierPaymentLedger($grn, $voucher, $payment, $user, 'mpesa');
            $this->refreshSupplierBalance($voucher->supplier_id);
            $voucher = $this->syncVoucherAndGrnAfterSettlement($voucher, $user);
            $txn->update(['payment_id' => $payment->grn_payment_id]);

            return $payment->fresh();
        });
    }

    public function generateReceipt(User $user, PaymentVoucher $voucher): PaymentVoucher
    {
        $this->authorizeStoreAccess($user, $voucher->store_id);

        return DB::transaction(function () use ($user, $voucher) {
            $record = PaymentVoucher::query()
                ->with($this->detailRelations())
                ->lockForUpdate()
                ->findOrFail($voucher->payment_voucher_id);

            $record = $this->syncVoucherAndGrnAfterSettlement($record, $user);
            $remainingBalance = round((float) ($record->balance_due ?? 0), 2);

            if ($remainingBalance > 0.009 || $this->normalizeStatus($record->status) !== self::STATUS_PAID) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Final receipt can only be generated after the voucher is fully paid.',
                    'data' => [
                        'remaining_balance' => $remainingBalance,
                        'payment_history' => $this->buildPaymentHistory($record),
                    ],
                ], 422));
            }

            $record = $this->maybeGenerateFinalReceipt($record, $user, true);

            return $this->present($record->fresh()->load($this->detailRelations()));
        });
    }

    public function generatePaymentSlip(PaymentVoucher $voucher, GrnPayment $payment, ?User $actor = null): GrnPayment
    {
        if (!$voucher->payment_voucher_id || !$payment->grn_payment_id || !Schema::hasColumn('grn_payments', 'slip_number')) {
            return $payment->fresh();
        }

        $installmentNumber = $payment->installment_number ?: $this->nextInstallmentNumber($voucher);
        $voucherToken = $this->formatVoucherToken($voucher);
        $slipNumber = $payment->slip_number ?: sprintf('SLIP-%s-%d', $voucherToken, $installmentNumber);
        $remainingAfterPayment = round(max((float) $voucher->amount - ($this->completedDisbursedForVoucher($voucher->payment_voucher_id) + (float) $payment->amount_paid), 0), 2);
        $slipType = $remainingAfterPayment <= 0.009 ? 'final_installment' : 'installment';

        $payload = [
            'slip_number' => $slipNumber,
            'installment_number' => $installmentNumber,
        ];

        if (Schema::hasColumn('grn_payments', 'slip_type')) {
            $payload['slip_type'] = $slipType;
        }

        if (Schema::hasColumn('grn_payments', 'slip_generated_at')) {
            $payload['slip_generated_at'] = $payment->slip_generated_at ?? now();
        }

        $payment->update($payload);

        return $payment->fresh()->loadMissing('user');
    }

    public function maybeGenerateFinalReceipt(PaymentVoucher $voucher, ?User $actor = null, bool $forceRegenerate = false): PaymentVoucher
    {
        if (!Schema::hasColumn('payment_vouchers', 'receipt_number')) {
            return $voucher->fresh()->load($this->detailRelations());
        }

        $voucher = $voucher->fresh()->load($this->detailRelations());
        $remainingBalance = round((float) ($voucher->balance_due ?? 0), 2);
        $status = $this->normalizeStatus($voucher->status);

        if ($remainingBalance > 0.009 || $status !== self::STATUS_PAID) {
            if ($forceRegenerate) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Final receipt is locked until the voucher balance reaches zero.',
                    'data' => [
                        'remaining_balance' => $remainingBalance,
                    ],
                ], 422));
            }

            return $voucher;
        }

        if (!$forceRegenerate && $voucher->receipt_number) {
            return $voucher;
        }

        $history = $this->buildPaymentHistory($voucher);
        $voucherToken = $this->formatVoucherToken($voucher);
        $receiptNumber = sprintf('RCP-%s-%s', $voucherToken, now()->format('ymdHi'));

        $payload = [
            'receipt_number' => $receiptNumber,
            'receipt_payment_breakdown' => $history,
        ];

        if (Schema::hasColumn('payment_vouchers', 'receipt_generated_at')) {
            $payload['receipt_generated_at'] = now();
        }

        if (Schema::hasColumn('payment_vouchers', 'receipt_generated_by_user_id')) {
            $payload['receipt_generated_by_user_id'] = $actor?->user_id ?? $voucher->approved_by_user_id ?? $voucher->prepared_by_user_id;
        }

        $voucher->update($payload);

        return $voucher->fresh()->load($this->detailRelations());
    }

    public function present(PaymentVoucher $voucher): PaymentVoucher
    {
        $voucher->loadMissing($this->detailRelations());
        return $this->appendWorkflowContext($voucher);
    }

    public function presentMany(iterable $vouchers): array
    {
        $rows = [];
        foreach ($vouchers as $voucher) {
            $rows[] = $this->present($voucher);
        }

        return $rows;
    }

    public function detailRelations(): array
    {
        return [
            'store',
            'supplier',
            'grn.store',
            'grn.supplier',
            'grn.purchaseOrder',
            'grn.items.purchaseOrderItem',
            'grn.items.product',
            'grn.payments.user',
            'purchaseOrder',
            'preparedBy',
            'approvedBy',
            'receiptGeneratedBy',
            'payments.user',
            'settledPayments.user',
        ];
    }

    public function recalculateVoucherFinancials(PaymentVoucher $voucher, ?User $actor = null, ?string $forcedStatus = null): PaymentVoucher
    {
        $voucher->loadMissing('payments');

        $paidAmount = round($this->completedDisbursedForVoucher($voucher->payment_voucher_id), 2);
        $balanceDue = round(max((float) $voucher->amount - $paidAmount, 0), 2);
        $currentStatus = $this->normalizeStatus($voucher->status);

        $status = $forcedStatus;
        if ($status === null) {
            if ($balanceDue <= 0) {
                $status = self::STATUS_PAID;
            } elseif ($paidAmount > 0) {
                $status = self::STATUS_PARTIALLY_PAID;
            } elseif (in_array($currentStatus, [self::STATUS_PROCESSING, self::STATUS_CANCELLED, self::STATUS_OVERRIDE_REQUIRED, self::STATUS_PENDING_APPROVAL, self::STATUS_AUTHORIZED, self::STATUS_DRAFT], true)) {
                $status = $currentStatus;
            } else {
                $status = self::STATUS_DRAFT;
            }
        }

        $voucher->update([
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'status' => $status,
            'approved_by_user_id' => $actor?->user_id ?? $voucher->approved_by_user_id,
        ]);

        return $voucher->fresh();
    }

    public function remainingBalanceForVoucher(PaymentVoucher $voucher): float
    {
        return round(max((float) $voucher->amount - $this->completedDisbursedForVoucher($voucher->payment_voucher_id), 0), 2);
    }


    private function syncVoucherAndGrnAfterSettlement(PaymentVoucher $voucher, ?User $actor = null): PaymentVoucher
    {
        $voucher = $this->recalculateVoucherFinancials($voucher->fresh()->load($this->detailRelations()), $actor);
        $this->syncLinkedGrnTotals($voucher->grn_id);
        $voucher = $this->maybeGenerateFinalReceipt($voucher, $actor);

        return $voucher->fresh()->load($this->detailRelations());
    }

    private function syncLinkedGrnTotals(int|string|null $grnId): ?Grn
    {
        if (!$grnId) {
            return null;
        }

        $grn = Grn::query()->lockForUpdate()->find($grnId);
        if (!$grn) {
            return null;
        }

        if ($this->grnService) {
            return $this->grnService->recalculateTotals($grn);
        }

        return $this->fallbackRecalculateGrnTotals($grn);
    }

    private function fallbackRecalculateGrnTotals(Grn $grn): Grn
    {
        $grn->load([
            'payments' => fn ($query) => $query
                ->whereNull('deleted_at')
                ->whereIn('status', ['posted', 'completed']),
        ]);

        $paidAmount = round((float) $grn->payments->sum('amount_paid'), 2);
        $finalTotal = round((float) ($grn->final_total ?? 0), 2);
        $balanceDue = round(max($finalTotal - $paidAmount, 0), 2);
        $paymentStatus = $paidAmount <= 0
            ? 'unpaid'
            : ($paidAmount >= $finalTotal ? 'paid' : 'partial');

        $grn->update([
            'paid_amount' => $paidAmount,
            'balance_due' => $balanceDue,
            'payment_status' => $paymentStatus,
            'last_payment_at' => $paidAmount > 0
                ? optional($grn->payments->sortByDesc('paid_at')->first())->paid_at
                : null,
        ]);

        return $grn->fresh();
    }

    private function appendWorkflowContext(PaymentVoucher $voucher): PaymentVoucher
    {
        $summary = $voucher->grn
            ? $this->buildThreeWayMatchSummary($voucher->grn, $voucher)
            : [
                'status' => 'variance',
                'label' => 'Voucher is missing its GRN context',
                'matched_lines' => 0,
                'variance_lines' => 0,
                'total_lines' => 0,
                'invoice_total_matched' => false,
                'issues' => ['GRN record not available for 3-way match verification.'],
                'lines' => [],
            ];

        $requiresOverride = ($summary['status'] ?? 'variance') !== 'matched';
        $currentStatus = $this->normalizeStatus($voucher->status);
        $totalDisbursed = $this->completedDisbursedForVoucher($voucher->payment_voucher_id);
        $remainingBalance = round(max((float) $voucher->amount - $totalDisbursed, 0), 2);
        $paymentHistory = $this->buildPaymentHistory($voucher);
        $installmentCount = count($paymentHistory);
        $latestPayment = $installmentCount > 0 ? $paymentHistory[$installmentCount - 1] : null;
        $receiptNumber = (string) ($voucher->receipt_number ?? '');
        $receiptAvailable = $receiptNumber !== '';
        $canGenerateReceipt = $currentStatus === self::STATUS_PAID && $remainingBalance <= 0.009;

        $voucher->setAttribute('match_summary', $summary);
        $voucher->setAttribute('requires_override', $requiresOverride);
        $voucher->setAttribute('total_disbursed', $totalDisbursed);
        $voucher->setAttribute('remaining_balance', $remainingBalance);
        $voucher->setAttribute('receipt_available', $receiptAvailable);
        $voucher->setAttribute('can_generate_receipt', $canGenerateReceipt);
        $voucher->setAttribute('receipt_number', $voucher->receipt_number);
        $voucher->setAttribute('receipt_generated_at', $voucher->receipt_generated_at);
        $voucher->setAttribute('receipt_payment_breakdown', $voucher->receipt_payment_breakdown);
        $voucher->setAttribute('payment_history', $paymentHistory);
        $voucher->setAttribute('installment_count', $installmentCount);
        $voucher->setAttribute('latest_slip_number', $latestPayment['slip_number'] ?? null);
        $voucher->setAttribute('payment_gate_status', [
            'locked' => $currentStatus === self::STATUS_OVERRIDE_REQUIRED,
            'processing' => $currentStatus === self::STATUS_PROCESSING,
            'remaining_balance' => $remainingBalance,
            'receipt_locked' => !$canGenerateReceipt,
        ]);
        $voucher->setAttribute('verifiedBy', $voucher->approvedBy);
        $voucher->setAttribute('prepared_by_name', $voucher->preparedBy ? $this->displayName($voucher->preparedBy) : null);
        $voucher->setAttribute('verified_by_name', $voucher->approvedBy ? $this->displayName($voucher->approvedBy) : null);
        $voucher->setAttribute(
            'authorized_by_name',
            $voucher->authorized_by ?: ($voucher->approvedBy ? $this->displayName($voucher->approvedBy) : null)
        );
        $voucher->setAttribute('approval_policy', [
            'can_submit' => $currentStatus === self::STATUS_DRAFT,
            'can_authorize' => in_array($currentStatus, [self::STATUS_PENDING_APPROVAL, self::STATUS_OVERRIDE_REQUIRED], true),
            'can_pay' => in_array($currentStatus, [self::STATUS_AUTHORIZED, self::STATUS_PARTIALLY_PAID], true),
            'requires_override' => $requiresOverride,
            'auto_authorize_ready' => !$requiresOverride,
            'next_step' => match ($currentStatus) {
                self::STATUS_OVERRIDE_REQUIRED => 'Override approval required before the payment window can unlock.',
                self::STATUS_PENDING_APPROVAL => 'Awaiting approval from the payment voucher desk.',
                self::STATUS_AUTHORIZED => 'Voucher is authorized and ready for a partial or full payout.',
                self::STATUS_PARTIALLY_PAID => 'A partial payout was posted. Print the installment slip and continue disbursing the remaining balance from the payment window.',
                self::STATUS_PROCESSING => 'M-Pesa payout is in progress. Wait for Safaricom callback before retrying.',
                self::STATUS_PAID => $receiptAvailable
                    ? 'Voucher liability is fully settled. Final receipt is ready for print.'
                    : 'Voucher liability is fully settled. Final receipt can now be generated.',
                default => 'Prepare the voucher and submit it to the approval desk.',
            },
        ]);

        return $voucher;
    }

    private function buildLineItemsFromGrn(Grn $grn): array
    {
        return collect($grn->items ?? [])->values()->map(function ($item, $index) use ($grn) {
            $orderedQty = (int) ($item->purchaseOrderItem?->quantity_ordered ?? (($item->previously_received ?? 0) + ($item->quantity_expected ?? 0)));
            $shippedQty = (int) ($item->quantity_expected ?? 0);
            $receivedQty = (int) ($item->quantity_accepted ?? $item->qty_received ?? 0);
            $rejectedQty = (int) ($item->quantity_rejected ?? 0);

            return [
                'table_no' => $index + 1,
                'particular' => $item->product_name_snapshot ?: ($item->product?->product_name ?? ('Line ' . ($index + 1))),
                'product_id' => $item->product_id,
                'invoice_no' => $grn->invoice_number,
                'ordered_qty' => $orderedQty,
                'shipped_qty' => $shippedQty,
                'received_qty' => $receivedQty,
                'rejected_qty' => $rejectedQty,
                'quantity' => $receivedQty,
                'free_qty' => (int) ($item->free_qty ?? 0),
                'unit_cost_excl_tax' => round((float) ($item->cost_price_excl_tax ?? 0), 2),
                'unit_cost_incl_tax' => round((float) ($item->cost_price_incl_tax ?? 0), 2),
                'amount' => round((float) ($item->total_amount ?? 0), 2),
            ];
        })->all();
    }

    private function buildThreeWayMatchSummary(?Grn $grn, ?PaymentVoucher $voucher = null): array
    {
        if (!$grn) {
            return [
                'status' => 'variance',
                'label' => 'Missing GRN context',
                'matched_lines' => 0,
                'variance_lines' => 0,
                'total_lines' => 0,
                'invoice_total_matched' => false,
                'issues' => ['GRN record not found.'],
                'lines' => [],
            ];
        }

        $lines = [];
        $aggregateIssues = [];
        $matchedLines = 0;
        $varianceLines = 0;

        foreach ($grn->items ?? [] as $index => $item) {
            $poQty = $item->purchaseOrderItem?->quantity_ordered;
            $dnQty = (int) ($item->quantity_expected ?? 0);
            $acceptedQty = (int) ($item->quantity_accepted ?? $item->qty_received ?? 0);
            $rejectedQty = (int) ($item->quantity_rejected ?? 0);
            $inspectedQty = $acceptedQty + $rejectedQty;
            $poUnitCost = $item->purchaseOrderItem?->unit_cost;
            $receivedUnitCost = (float) ($item->cost_price_excl_tax ?? 0);
            $issues = [];

            if ($poQty !== null && $dnQty > (int) $poQty) {
                $issues[] = sprintf('Supplier shipped %d more than the PO-allowed quantity of %d.', $dnQty, (int) $poQty);
            }

            if ($inspectedQty !== $dnQty) {
                $issues[] = sprintf('GRN inspected quantity (%d) does not match delivery-note quantity (%d).', $inspectedQty, $dnQty);
            }

            if ($rejectedQty > 0) {
                $issues[] = sprintf('%d item(s) were rejected during GRN inspection.', $rejectedQty);
            }

            if ($poUnitCost !== null && abs((float) $poUnitCost - $receivedUnitCost) >= 0.01) {
                $issues[] = sprintf('PO unit cost %0.2f differs from GRN unit cost %0.2f.', (float) $poUnitCost, $receivedUnitCost);
            }

            $state = empty($issues) ? 'matched' : 'variance';
            if ($state === 'matched') {
                $matchedLines++;
            } else {
                $varianceLines++;
                $aggregateIssues = array_merge($aggregateIssues, $issues);
            }

            $lines[] = [
                'line_no' => $index + 1,
                'product_id' => $item->product_id,
                'particular' => $item->product_name_snapshot ?: ($item->product?->product_name ?? ('Line ' . ($index + 1))),
                'po_ordered_qty' => $poQty !== null ? (int) $poQty : null,
                'delivery_note_qty' => $dnQty,
                'grn_accepted_qty' => $acceptedQty,
                'grn_rejected_qty' => $rejectedQty,
                'inspected_qty' => $inspectedQty,
                'po_unit_cost' => $poUnitCost !== null ? round((float) $poUnitCost, 2) : null,
                'grn_unit_cost' => round($receivedUnitCost, 2),
                'match_state' => $state,
                'issues' => $issues,
            ];
        }

        $invoiceTotalMatched = true;
        if ($grn->invoice_reference_total !== null && $grn->invoice_reference_total !== '') {
            $invoiceTotalMatched = abs((float) $grn->invoice_reference_total - (float) $grn->final_total) < 0.01;
            if (!$invoiceTotalMatched) {
                $aggregateIssues[] = sprintf(
                    'Invoice reference total %0.2f does not match GRN final total %0.2f.',
                    (float) $grn->invoice_reference_total,
                    (float) $grn->final_total
                );
            }
        }

        $hasVariance = $varianceLines > 0 || !$invoiceTotalMatched;
        $issues = array_values(array_unique(array_filter($aggregateIssues)));

        return [
            'status' => $hasVariance ? 'variance' : 'matched',
            'label' => $hasVariance ? 'Variance detected — override required' : 'Matched — ready for approval',
            'matched_lines' => $matchedLines,
            'variance_lines' => $varianceLines,
            'total_lines' => count($lines),
            'invoice_total_matched' => $invoiceTotalMatched,
            'voucher_amount' => round((float) ($voucher?->amount ?? $grn->balance_due ?? 0), 2),
            'grn_total' => round((float) ($grn->final_total ?? 0), 2),
            'invoice_reference_total' => $grn->invoice_reference_total !== null ? round((float) $grn->invoice_reference_total, 2) : null,
            'issues' => $issues,
            'lines' => $lines,
        ];
    }


    private function buildPaymentHistory(PaymentVoucher $voucher): array
    {
        $payments = $voucher->relationLoaded('settledPayments')
            ? $voucher->settledPayments
            : $voucher->settledPayments()->with('user')->get();

        return $payments
            ->sortBy([
                ['paid_at', 'asc'],
                ['grn_payment_id', 'asc'],
            ])
            ->values()
            ->map(function (GrnPayment $payment) use ($voucher) {
                return [
                    'grn_payment_id' => (int) $payment->grn_payment_id,
                    'payment_number' => $payment->payment_number,
                    'slip_number' => $payment->slip_number,
                    'slip_type' => $payment->slip_type ?: ((float) $payment->amount_paid >= (float) $voucher->amount ? 'full' : 'installment'),
                    'installment_number' => (int) ($payment->installment_number ?: 0),
                    'payment_method' => $payment->payment_method,
                    'amount_paid' => round((float) $payment->amount_paid, 2),
                    'amount_received' => round((float) ($payment->amount_received ?? $payment->amount_paid), 2),
                    'paid_at' => optional($payment->paid_at)->toISOString() ?: optional($payment->created_at)->toISOString(),
                    'processed_by_name' => $payment->relationLoaded('user') || $payment->user
                        ? $this->displayName($payment->user)
                        : null,
                    'notes' => $payment->notes,
                    'mpesa_code' => $payment->mpesa_code,
                    'bank_reference' => $payment->bank_reference,
                ];
            })
            ->all();
    }

    private function nextInstallmentNumber(PaymentVoucher $voucher): int
    {
        return max((int) GrnPayment::query()
            ->where('payment_voucher_id', (int) $voucher->payment_voucher_id)
            ->withTrashed()
            ->max('installment_number'), 0) + 1;
    }

    private function formatVoucherToken(PaymentVoucher $voucher): string
    {
        return 'PV' . str_pad((string) $voucher->payment_voucher_id, 6, '0', STR_PAD_LEFT);
    }

    private function completedDisbursedForVoucher(int|string|null $voucherId): float
    {
        if (!$voucherId) {
            return 0.0;
        }

        return round((float) GrnPayment::query()
            ->where('payment_voucher_id', (int) $voucherId)
            ->whereIn('status', ['completed', 'posted'])
            ->sum('amount_paid'), 2);
    }

    private function assertPayoutAllowed(PaymentVoucher $voucher): void
    {
        $status = $this->normalizeStatus($voucher->status);

        if ($status === self::STATUS_OVERRIDE_REQUIRED) {
            throw new HttpResponseException(response()->json([
                'message' => 'Payments are blocked while the voucher is in OVERRIDE_REQUIRED state.',
            ], 422));
        }

        if ($status === self::STATUS_PROCESSING) {
            throw new HttpResponseException(response()->json([
                'message' => 'This voucher already has an M-Pesa payout in flight. Wait for the callback before retrying.',
            ], 422));
        }

        if (in_array($status, [self::STATUS_PAID, self::STATUS_CANCELLED], true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This voucher cannot accept additional payouts.',
            ], 422));
        }

        if (!in_array($status, [self::STATUS_AUTHORIZED, self::STATUS_PARTIALLY_PAID], true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Payments can only execute when the voucher is AUTHORIZED, APPROVED, or PARTIALLY_PAID.',
            ], 422));
        }
    }

    private function assertCashDrawerCanCover(User $user, PaymentVoucher $voucher, float $amount): void
    {
        $this->cashierShiftService?->requireOpenShift($user, (int) $voucher->store_id);

        if (!Schema::hasTable('cashier_shifts')) {
            return;
        }

        $query = DB::table('cashier_shifts')->where('store_id', (int) $voucher->store_id);

        if (Schema::hasColumn('cashier_shifts', 'user_id')) {
            $query->where('user_id', (int) $user->user_id);
        }

        if (Schema::hasColumn('cashier_shifts', 'status')) {
            $query->where('status', 'open');
        } elseif (Schema::hasColumn('cashier_shifts', 'closed_at')) {
            $query->whereNull('closed_at');
        }

        $shift = $query->orderByDesc(Schema::hasColumn('cashier_shifts', 'cashier_shift_id') ? 'cashier_shift_id' : 'store_id')->first();
        if (!$shift) {
            return;
        }

        $available = null;
        foreach (['current_cash', 'expected_cash', 'expected_drawer_cash', 'cash_in_hand'] as $column) {
            if (property_exists($shift, $column) && $shift->{$column} !== null) {
                $available = round((float) $shift->{$column}, 2);
                break;
            }
        }

        if ($available !== null && $amount > $available) {
            throw new HttpResponseException(response()->json([
                'message' => 'Cash drawer balance is below the requested payout amount.',
                'data' => [
                    'drawer_balance' => $available,
                    'requested_amount' => round($amount, 2),
                ],
            ], 422));
        }
    }

    private function recordSupplierPaymentLedger(?Grn $grn, PaymentVoucher $voucher, GrnPayment $payment, User $user, string $method): void
    {
        SupplierLedgerEntry::query()->firstOrCreate(
            [
                'grn_payment_id' => $payment->grn_payment_id,
                'entry_type' => 'payment',
            ],
            [
                'supplier_id' => $voucher->supplier_id,
                'store_id' => $voucher->store_id,
                'purchase_order_id' => $voucher->purchase_order_id,
                'grn_id' => $voucher->grn_id,
                'created_by_user_id' => $user->user_id,
                'direction' => 'credit',
                'reference_number' => $payment->payment_number,
                'description' => sprintf(
                    'Supplier payout via %s · Voucher %s · Ledger credit to %s',
                    strtoupper($method),
                    $voucher->voucher_number ?: $voucher->payment_voucher_id,
                    $method === 'cash' ? 'Cash on Hand' : 'M-Pesa Float'
                ),
                'amount' => (float) $payment->amount_paid,
                'entry_date' => $payment->paid_at ?? now(),
                'meta' => [
                    'payment_method' => $method,
                    'payment_voucher_id' => $voucher->payment_voucher_id,
                    'payment_voucher_number' => $voucher->voucher_number,
                    'ap_ledger_effect' => 'debit_accounts_payable',
                    'offset_account' => $method === 'cash' ? 'cash_on_hand' : 'mpesa_float_account',
                    'grn_number' => $grn?->grn_number,
                ],
            ]
        );
    }

    private function refreshSupplierBalance(int|string|null $supplierId): void
    {
        if (!$supplierId) {
            return;
        }

        $supplier = Supplier::query()->find($supplierId);
        if ($supplier && method_exists($supplier, 'refreshCurrentBalance')) {
            $supplier->refreshCurrentBalance();
        }
    }

    private function canApproveVariance(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $role = strtolower((string) ($user->role ?? ''));
        return in_array($role, ['admin', 'manager'], true);
    }

    private function appendOverrideAuditNote(?string $existingNotes, User $user, string $overrideNote, array $summary): string
    {
        $note = sprintf(
            '[3-WAY MATCH OVERRIDE] %s approved a voucher with %d variance line(s). Reason: %s',
            $this->displayName($user),
            (int) ($summary['variance_lines'] ?? 0),
            trim($overrideNote)
        );

        $existing = trim((string) $existingNotes);
        return $existing !== '' ? ($existing . "\n\n" . $note) : $note;
    }

    private function normalizeStatus(string|null $status): string
    {
        $safe = strtolower((string) $status);

        return match ($safe) {
            'approved' => self::STATUS_AUTHORIZED,
            'partial' => self::STATUS_PARTIALLY_PAID,
            'pending_settlement' => self::STATUS_PROCESSING,
            default => $safe ?: self::STATUS_DRAFT,
        };
    }

    private function displayName(?User $user): string
    {
        if (!$user) {
            return 'System';
        }

        $full = trim(collect([$user->first_name, $user->last_name])->filter()->join(' '));
        return $full !== '' ? $full : ($user->email ?? 'System');
    }
}
