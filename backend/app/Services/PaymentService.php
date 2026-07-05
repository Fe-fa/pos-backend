<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
        private readonly LoyaltyService $loyaltyService,
        private readonly CashierShiftService $cashierShiftService,
    ) {
    }

    public function charge(Billing $billing, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($billing, $user, $data) {
            $billing = Billing::query()
                ->whereKey($billing->billing_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (method_exists($billing, 'trashed') && $billing->trashed()) {
                $this->abort422('Cannot record payment for a trashed billing.');
            }

            if ($billing->status === 'paid') {
                $this->abort422('This billing has already been fully paid.');
            }

            $this->cashierShiftService->requireOpenShift($user, (int) $billing->store_id);

            $billing = $this->billingService->finalizeIfNeeded($billing, $user);
            $billing = Billing::query()
                ->whereKey($billing->billing_id)
                ->lockForUpdate()
                ->firstOrFail();

            $billing->loadMissing('items.product');
            $pointsToRedeem = $this->sanitizePointsToRedeem($billing, (int) ($data['points_redeemed'] ?? 0));

            if ($pointsToRedeem > 0 && $billing->customer_id) {
                $this->applyPointsRedemption($billing, $pointsToRedeem);
                $billing = Billing::query()
                    ->whereKey($billing->billing_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $billing->loadMissing('items.product');
            }

            $allocations = $this->normalisePaymentAllocations($data);
            $zeroPaymentMethod = $data['payment_method'] ?? ($allocations[0]['payment_method'] ?? 'cash');

            if ((float) $billing->balance_due <= 0) {
                return $this->completeZeroBalanceBilling($billing, $zeroPaymentMethod);
            }

            foreach ($allocations as $allocation) {
                if ($allocation['payment_method'] === 'mpesa' && ($allocation['mpesa_mode'] ?? null) === 'stk') {
                    $this->abort422('Live M-Pesa STK allocations must be completed from the M-Pesa callback flow.');
                }
            }

            [$openBillings, $previousDebtsSum] = $this->loadOpenBillingsForAllocation($billing, $billing->customer_id);
            $totalCombinedBalance = round((float) $openBillings->sum('balance_due'), 2);
            if ($totalCombinedBalance <= 0) {
                $this->abort422('This customer has no outstanding balances due.');
            }

            $allocationsTotal = round(array_reduce($allocations, function ($sum, $allocation) {
                return $sum + (float) $allocation['amount_received'];
            }, 0), 2);

            if (abs($allocationsTotal - $totalCombinedBalance) > 0.01) {
                $this->abort422('Remaining balance must be exactly zero before processing the sale.');
            }

            $primaryPaymentRecord = $this->applyAllocations($billing, $openBillings, $allocations);

            if (!$primaryPaymentRecord) {
                $this->abort422('No payment could be created for this sale.');
            }

            if (!empty($billing->customer_id)) {
                $freshCustomerBalance = round((float) Billing::where('customer_id', $billing->customer_id)
                    ->where('status', '!=', 'paid')
                    ->where('is_draft', false)
                    ->sum('balance_due'), 2);

                Customer::where('customer_id', $billing->customer_id)->update([
                    'current_balance' => $freshCustomerBalance,
                ]);
            }

            $freshPayment = $primaryPaymentRecord->fresh();
            $currentInvoice = Billing::query()->findOrFail($billing->billing_id);
            $freshPayment->balance_calculation_label = '+' . round((float) $currentInvoice->balance_due, 2) . '/+' . $previousDebtsSum;

            $this->applyPostPaymentLoyalty($currentInvoice);

            return $freshPayment->load([
                'billing.customer',
                'billing.store',
                'billing.user',
            ]);
        });
    }

    public function chargeCart(User $user, array $data): array
    {
        $this->billingService->authorizeStoreAccess($user, $data['store_id']);
        $this->cashierShiftService->requireOpenShift($user, (int) $data['store_id']);

        return DB::transaction(function () use ($user, $data) {
            $billing = Billing::create([
                'store_id' => $data['store_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => $user->user_id,
                'invnumber' => null,
                'status' => 'unpaid',
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance_due' => 0,
                'is_draft' => true,
                'billing_date' => now(),
                'notes' => $data['notes'] ?? null,
                'fulfillment_status' => 'pending',
                'fulfillment_type' => 'walk_in_counter',
            ]);

            foreach ($data['items'] as $item) {
                $qty = (int) $item['quantity'];
                $unitPrice = round((float) $item['price'], 2);
                $vatRate = (float) ($item['vat_rate'] ?? 16);

                $totalAmount = $qty * $unitPrice;
                $lineSubtotal = $totalAmount / (1 + ($vatRate / 100));
                $vatAmount = $totalAmount - $lineSubtotal;

                $billing->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'vat_rate' => $vatRate,
                    'line_subtotal' => round($lineSubtotal, 2),
                    'vat_amount' => round($vatAmount, 2),
                    'total_amount' => round($totalAmount, 2),
                ]);
            }

            $billing = $this->billingService->recalculateTotals($billing->fresh());

            $chargePayload = [
                'points_redeemed' => (int) ($data['points_redeemed'] ?? 0),
            ];

            if (!empty($data['payment_allocations']) && is_array($data['payment_allocations'])) {
                $chargePayload['payment_allocations'] = $data['payment_allocations'];
            } else {
                $chargePayload = [
                    ...$chargePayload,
                    'payment_method' => $data['payment_method'],
                    'amount_received' => (float) ($data['amount_received'] ?? $data['amount_tendered'] ?? 0),
                    'amount_tendered' => (float) ($data['amount_tendered'] ?? 0),
                    'mpesa_phone' => $data['mpesa_phone'] ?? null,
                    'mpesa_code' => $data['mpesa_code'] ?? null,
                    'card_reference' => $data['card_reference'] ?? null,
                    'card_holder' => $data['card_holder'] ?? null,
                ];
            }

            $payment = $this->charge($billing, $user, $chargePayload);

            $freshBilling = Billing::with([
                'customer',
                'store',
                'user',
                'items.product.category',
                'payments',
            ])->findOrFail($billing->billing_id);

            return [
                'billing' => $freshBilling,
                'payment' => $payment->fresh()->load([
                    'billing.customer',
                    'billing.store',
                    'billing.user',
                ]),
            ];
        });
    }

    private function sanitizePointsToRedeem(Billing $billing, int $pointsToRedeem): int
    {
        if ($pointsToRedeem < 0) {
            $this->abort422('Points to redeem cannot be negative.');
        }

        if ($pointsToRedeem <= 0 || !$billing->customer_id) {
            return 0;
        }

        $customerPoints = $this->loyaltyService->getCustomerPoints(
            (int) $billing->store_id,
            (int) $billing->customer_id
        );

        $pointsToRedeem = min($pointsToRedeem, $customerPoints);
        if ($pointsToRedeem <= 0) {
            return 0;
        }

        $activeRule = $this->loyaltyService->getActiveRule((int) $billing->store_id);
        $pointValue = (float) ($activeRule?->point_value ?? 1);
        $maxByInvoice = $pointValue > 0
            ? (int) floor((float) $billing->total / $pointValue)
            : 0;

        return max(min($pointsToRedeem, $maxByInvoice), 0);
    }

    private function applyPointsRedemption(Billing $billing, int $pointsToRedeem): void
    {
        try {
            $redemption = $this->loyaltyService->redeemPoints(
                storeId: (int) $billing->store_id,
                customerId: (int) $billing->customer_id,
                billingId: (int) $billing->billing_id,
                pointsToRedeem: $pointsToRedeem,
            );

            $pointsDiscount = (float) $redemption['discount_amount'];

            $billing->update([
                'total' => max((float) $billing->total - $pointsDiscount, 0),
                'balance_due' => max((float) $billing->balance_due - $pointsDiscount, 0),
                'points_discount' => $pointsDiscount,
            ]);
        } catch (\Throwable $e) {
            $this->abort422($e->getMessage());
        }
    }

    private function completeZeroBalanceBilling(Billing $billing, string $paymentMethod): Payment
    {
        $billing->update([
            'paid_amount' => (float) $billing->total,
            'balance_due' => 0,
            'status' => 'paid',
            'is_draft' => false,
        ]);

        $payment = Payment::create([
            'billing_id' => $billing->billing_id,
            'receiptnumber' => $this->documentNumberService->nextNumber($billing->store_id, 'Receipt'),
            'payment_method' => $paymentMethod,
            'amount_received' => 0,
            'amount_tendered' => 0,
            'change_returned' => 0,
            'balance_before' => 0,
            'balance_after' => 0,
            'payment_date' => now(),
        ]);

        if (!empty($billing->customer_id)) {
            $freshBalance = round((float) Billing::where('customer_id', $billing->customer_id)
                ->where('status', '!=', 'paid')
                ->where('is_draft', false)
                ->sum('balance_due'), 2);

            Customer::where('customer_id', $billing->customer_id)
                ->update(['current_balance' => $freshBalance]);
        }

        $this->applyPostPaymentLoyalty($billing->fresh());

        return $payment->load(['billing.customer', 'billing.store', 'billing.user']);
    }

    private function loadOpenBillingsForAllocation(Billing $billing, ?int $customerId): array
    {
        if (empty($customerId)) {
            return [collect([$billing]), 0.0];
        }

        $previousDebtsSum = round((float) Billing::where('customer_id', $customerId)
            ->where('billing_id', '!=', $billing->billing_id)
            ->where('status', '!=', 'paid')
            ->where('is_draft', false)
            ->sum('balance_due'), 2);

        $openBillings = Billing::where('customer_id', $customerId)
            ->where('status', '!=', 'paid')
            ->where('is_draft', false)
            ->lockForUpdate()
            ->orderBy('created_at')
            ->get();

        return [$openBillings, $previousDebtsSum];
    }

    private function normalisePaymentAllocations(array $data): array
    {
        if (!empty($data['payment_allocations']) && is_array($data['payment_allocations'])) {
            return array_values(array_filter(array_map(
                fn (array $row) => $this->normaliseAllocationRow($row),
                $data['payment_allocations']
            )));
        }

        if (empty($data['payment_method'])) {
            return [];
        }

        return [$this->normaliseAllocationRow([
            'payment_method' => $data['payment_method'],
            'amount_received' => $data['amount_received'] ?? 0,
            'amount_tendered' => $data['amount_tendered'] ?? null,
            'mpesa_phone' => $data['mpesa_phone'] ?? null,
            'mpesa_code' => $data['mpesa_code'] ?? null,
            'mpesa_mode' => $data['mpesa_mode'] ?? null,
            'card_reference' => $data['card_reference'] ?? null,
            'card_holder' => $data['card_holder'] ?? null,
        ])];
    }

    private function normaliseAllocationRow(array $row): array
    {
        $method = strtolower(trim((string) ($row['payment_method'] ?? '')));
        if (!in_array($method, ['cash', 'mpesa', 'card'], true)) {
            $this->abort422('Unsupported payment method in split allocation.');
        }

        $amountReceived = round((float) ($row['amount_received'] ?? 0), 2);
        if ($amountReceived <= 0) {
            $this->abort422('Each payment allocation must be greater than zero.');
        }

        $amountTendered = array_key_exists('amount_tendered', $row) && $row['amount_tendered'] !== null
            ? round((float) $row['amount_tendered'], 2)
            : $amountReceived;

        $mpesaMode = $method === 'mpesa'
            ? strtolower(trim((string) ($row['mpesa_mode'] ?? 'manual')))
            : null;

        $normalized = [
            'payment_method' => $method,
            'amount_received' => $amountReceived,
            'amount_tendered' => $amountTendered,
            'mpesa_phone' => $method === 'mpesa' ? trim((string) ($row['mpesa_phone'] ?? '')) : null,
            'mpesa_code' => $method === 'mpesa' ? strtoupper(trim((string) ($row['mpesa_code'] ?? ''))) : null,
            'mpesa_mode' => $mpesaMode,
            'card_reference' => $method === 'card' ? trim((string) ($row['card_reference'] ?? '')) : null,
            'card_holder' => $method === 'card' ? trim((string) ($row['card_holder'] ?? '')) : null,
        ];

        if ($method === 'cash' && $amountTendered < $amountReceived) {
            $this->abort422('Cash tendered cannot be less than the allocated cash amount.');
        }

        if ($method === 'mpesa') {
            if ($normalized['mpesa_phone'] === '') {
                $this->abort422('Every M-Pesa allocation requires a phone number.');
            }
            if (!in_array($mpesaMode, ['stk', 'manual'], true)) {
                $this->abort422('Every M-Pesa allocation must declare stk or manual mode.');
            }
            if ($mpesaMode === 'manual' && $normalized['mpesa_code'] === '') {
                $this->abort422('Manual M-Pesa allocations require a transaction code.');
            }
        }

        if ($method === 'card' && $normalized['card_reference'] === '') {
            $this->abort422('Every card allocation requires a card reference.');
        }

        return $normalized;
    }

    private function applyAllocations(Billing $billing, Collection $openBillings, array $allocations): ?Payment
    {
        $primaryPaymentRecord = null;

        foreach ($allocations as $allocation) {
            $allocationAmountLeft = round((float) $allocation['amount_received'], 2);
            $tenderedForAllocation = round((float) ($allocation['amount_tendered'] ?? $allocation['amount_received']), 2);
            $changeForAllocation = $allocation['payment_method'] === 'cash'
                ? round(max($tenderedForAllocation - (float) $allocation['amount_received'], 0), 2)
                : 0.0;
            $tenderedAssigned = false;
            $changeAssigned = false;

            foreach ($openBillings as $currentBill) {
                if ($allocationAmountLeft <= 0) {
                    break;
                }

                $billBalanceBefore = round((float) $currentBill->balance_due, 2);
                if ($billBalanceBefore <= 0) {
                    continue;
                }

                $paymentToApply = round(min($allocationAmountLeft, $billBalanceBefore), 2);
                if ($paymentToApply <= 0) {
                    continue;
                }

                $payment = Payment::create([
                    'billing_id' => $currentBill->billing_id,
                    'receiptnumber' => $this->documentNumberService->nextNumber($currentBill->store_id, 'Receipt'),
                    'payment_method' => $allocation['payment_method'],
                    'amount_received' => $paymentToApply,
                    'amount_tendered' => $tenderedAssigned ? $paymentToApply : $tenderedForAllocation,
                    'change_returned' => $changeAssigned ? 0.00 : $changeForAllocation,
                    'balance_before' => $billBalanceBefore,
                    'balance_after' => round(max($billBalanceBefore - $paymentToApply, 0), 2),
                    'payment_date' => now(),
                ]);

                if ((int) $currentBill->billing_id === (int) $billing->billing_id && $primaryPaymentRecord === null) {
                    $primaryPaymentRecord = $payment;
                } elseif ($primaryPaymentRecord === null) {
                    $primaryPaymentRecord = $payment;
                }

                $paid = round((float) $currentBill->payments()->sum('amount_received'), 2);
                $balance = round(max((float) $currentBill->total - $paid, 0), 2);

                $currentBill->update([
                    'paid_amount' => $paid,
                    'balance_due' => $balance,
                    'status' => $balance <= 0 ? 'paid' : 'partial',
                    'is_draft' => false,
                ]);

                $currentBill->paid_amount = $paid;
                $currentBill->balance_due = $balance;
                $currentBill->status = $balance <= 0 ? 'paid' : 'partial';
                $currentBill->is_draft = false;

                $this->auditLogService->log(
                    'payment.create',
                    $payment,
                    null,
                    $payment->toArray(),
                    [
                        'billing_uuid' => $currentBill->uuid,
                        'billing_id' => $currentBill->billing_id,
                        'invoice_number' => $currentBill->invnumber,
                        'payment_method' => $allocation['payment_method'],
                    ],
                    $currentBill->store_id
                );

                $allocationAmountLeft = round($allocationAmountLeft - $paymentToApply, 2);
                $tenderedAssigned = true;
                $changeAssigned = true;
            }

            if ($allocationAmountLeft > 0.01) {
                $this->abort422('A payment allocation exceeded the available outstanding balance.');
            }
        }

        return $primaryPaymentRecord;
    }

    private function applyPostPaymentLoyalty(Billing $billing): void
    {
        if (empty($billing->customer_id)) {
            return;
        }

        try {
            $this->loyaltyService->earnPoints(
                storeId: (int) $billing->store_id,
                customerId: (int) $billing->customer_id,
                billingId: (int) $billing->billing_id,
                saleAmount: (float) $billing->total,
            );
        } catch (\Throwable $e) {
            \Log::warning('Loyalty points earn failed: ' . $e->getMessage());
        }

        try {
            $billing->loadMissing('items.product');
            $rule = $this->loyaltyService->getActiveRule((int) $billing->store_id);

            if ($rule && $rule->chapa5_enabled && filled($rule->chapa5_product_sku)) {
                $promoSku = strtolower(trim((string) $rule->chapa5_product_sku));

                $qualifyingQty = (int) $billing->items
                    ->filter(function ($item) use ($promoSku) {
                        $itemSku = strtolower(trim((string) data_get($item, 'product.sku')));
                        $qty = (int) ($item->quantity ?? 0);
                        $unitPrice = (float) ($item->unit_price ?? 0);
                        $lineTotal = (float) ($item->total_amount ?? ($qty * $unitPrice));

                        return $itemSku !== ''
                            && $itemSku === $promoSku
                            && $qty > 0
                            && $unitPrice > 0
                            && $lineTotal > 0;
                    })
                    ->sum(function ($item) {
                        return (int) ($item->quantity ?? 0);
                    });

                if ($qualifyingQty > 0) {
                    $this->loyaltyService->applyChapa5(
                        storeId: (int) $billing->store_id,
                        customerId: (int) $billing->customer_id,
                        billingId: (int) $billing->billing_id,
                        sku: $rule->chapa5_product_sku,
                        itemsBought: (int) $qualifyingQty,
                    );
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Chapa 5 apply failed: ' . $e->getMessage());
        }
    }

    private function abort422(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
        ], 422));
    }
}
