<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Payment;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly BillingService        $billingService,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService       $auditLogService,
        private readonly LoyaltyService        $loyaltyService,
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
                abort(response()->json([
                    'message' => 'Cannot record payment for a trashed billing.',
                ], 422));
            }

            if ($billing->status === 'paid') {
                abort(response()->json([
                    'message' => 'This billing has already been fully paid.',
                ], 422));
            }

            // Finalize billing once only.
            $billing = $this->billingService->finalizeIfNeeded($billing, $user);

            // Re-lock and refresh after finalization
            $billing = Billing::query()
                ->whereKey($billing->billing_id)
                ->lockForUpdate()
                ->firstOrFail();

            $billing = $this->billingService->recalculateTotals($billing);

            // ✅ ADD HERE — redeem loyalty points and apply discount before payment distribution
            if (!empty($data['points_redeemed']) && $data['points_redeemed'] > 0 && $billing->customer_id) {
                try {
                    $redemption = $this->loyaltyService->redeemPoints(
                        storeId:        (int) $billing->store_id,
                        customerId:     (int) $billing->customer_id,
                        billingId:      (int) $billing->billing_id,
                        pointsToRedeem: (int) $data['points_redeemed'],
                    );
                    // Reduce the bill total by the discount
                    $discountAmount = $redemption['discount_amount'];
                    $billing->update([
                        'total'       => max($billing->total - $discountAmount, 0),
                        'balance_due' => max($billing->balance_due - $discountAmount, 0),
                    ]);
                    $billing = $billing->fresh();
                } catch (\Throwable $e) {
                    abort(response()->json(['message' => $e->getMessage()], 422));
                }
            }

            $method     = $data['payment_method'];
            $customerId = $billing->customer_id;

            // 1. Isolate debt retrieval STRICTLY to this specific customer
            if (empty($customerId)) {
                $allCustomerBillings = collect([$billing]);
                $previousDebtsSum    = 0.00;
            } else {
                // Get previous unpaid balances excluding the current active checkout record
                $previousDebtsSum = round((float) Billing::where('customer_id', $customerId)
                    ->where('billing_id', '!=', $billing->billing_id)
                    ->where('status', '!=', 'paid')
                    ->sum('balance_due'), 2);

                $allCustomerBillings = Billing::where('customer_id', $customerId)
                    ->where('status', '!=', 'paid')
                    ->lockForUpdate()
                    ->get();
            }

            $totalCombinedBalance = round((float) $allCustomerBillings->sum('balance_due'), 2);

            // 2. Get raw monetary values handed over by the customer
            $rawTendered = array_key_exists('amount_tendered', $data)
                ? round((float) $data['amount_tendered'], 2)
                : round((float) $data['amount_received'], 2);

            if ($rawTendered <= 0) {
                abort(response()->json(['message' => 'Amount tendered must be greater than zero.'], 422));
            }

            if ($totalCombinedBalance <= 0) {
                abort(response()->json(['message' => 'This customer has no outstanding balances due.'], 422));
            }

            // 3. Track cash distribution variables
            $moneyLeftToAllocate = $rawTendered;
            $globalChangeReturned = 0.00;

            // Change is now strictly calculated from: Tendered - (Current Invoice + Outstanding Customer Balances)
            if ($method === 'cash' && $rawTendered > $totalCombinedBalance) {
                $globalChangeReturned = round($rawTendered - $totalCombinedBalance, 2);
            }

            $sortedBillings      = $allCustomerBillings->sortBy('created_at');
            $primaryPaymentRecord = null;

            // 4. Distribute the payment across the customer's ledger entries
            foreach ($sortedBillings as $currentBill) {
                if ($moneyLeftToAllocate <= 0) {
                    break;
                }

                $billBalanceBefore = round((float) $currentBill->balance_due, 2);
                $paymentToApply    = min($moneyLeftToAllocate, $billBalanceBefore);
                $isCurrentInvoice  = ($currentBill->billing_id === $billing->billing_id);

                $payment = Payment::create([
                    'billing_id'      => $currentBill->billing_id,
                    'receiptnumber'   => $this->documentNumberService->nextNumber($currentBill->store_id, 'Receipt'),
                    'payment_method'  => $method,
                    'amount_received' => $paymentToApply,
                    'amount_tendered' => $isCurrentInvoice ? $rawTendered : $paymentToApply,
                    'change_returned' => $isCurrentInvoice ? $globalChangeReturned : 0.00,
                    'balance_before'  => $billBalanceBefore,
                    'balance_after'   => round(max($billBalanceBefore - $paymentToApply, 0), 2),
                    'payment_date'    => now(),
                ]);

                if ($isCurrentInvoice || $primaryPaymentRecord === null) {
                    $primaryPaymentRecord = $payment;
                }

                $paid    = round((float) $currentBill->payments()->sum('amount_received'), 2);
                $balance = round(max((float) $currentBill->total - $paid, 0), 2);

                $currentBill->update([
                    'paid_amount' => $paid,
                    'balance_due' => $balance,
                    'status'      => $balance <= 0 ? 'paid' : 'partial',
                    'is_draft'    => false,
                ]);

                $this->auditLogService->log(
                    'payment.create',
                    $payment,
                    null,
                    $payment->toArray(),
                    [
                        'billing_uuid'   => $currentBill->uuid,
                        'billing_id'     => $currentBill->billing_id,
                        'invoice_number' => $currentBill->invnumber,
                    ],
                    $currentBill->store_id
                );

                $moneyLeftToAllocate -= $paymentToApply;
            }

            // 5. Synchronize the physical 'current_balance' column on the customers table
            if (!empty($customerId)) {
                $freshCustomerBalance = round((float) Billing::where('customer_id', $customerId)
                    ->where('status', '!=', 'paid')
                    ->sum('balance_due'), 2);

                Customer::where('customer_id', $customerId)->update([
                    'current_balance' => $freshCustomerBalance,
                ]);
            }

            // Add runtime data string attributes to return object payload
            $freshPayment = $primaryPaymentRecord->fresh();
            $currentInvoiceBalance = round((float) $billing->balance_due, 2);
            $freshPayment->balance_calculation_label = "+{$currentInvoiceBalance}/+{$previousDebtsSum}";

            // 6. Earn loyalty points if customer is attached (non-critical)
            if (!empty($customerId)) {
                try {
                    $this->loyaltyService->earnPoints(
                        storeId:    (int) $billing->store_id,
                        customerId: (int) $customerId,
                        billingId:  (int) $billing->billing_id,
                        saleAmount: (float) $billing->total,
                    );
                } catch (\Throwable $e) {
                    // Non-critical — log but don't fail the payment
                    \Log::warning("Loyalty points earn failed: " . $e->getMessage());
                }
            }
            // At the end of charge(), after earnPoints, add Chapa 5:

// 7. Apply Chapa 5 punch card if enabled (non-critical)
if (!empty($customerId)) {
    try {
        // Count total items in this billing
        $totalItems = $billing->items->sum('quantity');

        $this->loyaltyService->applyChapa5(
            storeId:     (int) $billing->store_id,
            customerId:  (int) $customerId,
            billingId:   (int) $billing->billing_id,
            itemsBought: (int) $totalItems,
        );
    } catch (\Throwable $e) {
        \Log::warning("Chapa 5 apply failed: " . $e->getMessage());
    }
}

            return $freshPayment->load([
                'billing.customer',
                'billing.store',
                'billing.user',
            ]);
        });
    }
}