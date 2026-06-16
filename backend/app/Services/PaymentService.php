<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
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

            // Finalize billing once only
            $billing = $this->billingService->finalizeIfNeeded($billing, $user);

            // Re-lock and refresh after finalization
            $billing = Billing::query()
                ->whereKey($billing->billing_id)
                ->lockForUpdate()
                ->firstOrFail();

            // $billing = $this->billingService->recalculateTotals($billing);
            $billing->loadMissing('items.product');

$pointsDiscount = 0.00;  // ← initialize before the validation block too

$pointsToRedeem = (int) ($data['points_redeemed'] ?? 0);


if ($pointsToRedeem < 0) {
    abort(response()->json(['message' => 'Points to redeem cannot be negative.'], 422));
}

if ($pointsToRedeem > 0 && $billing->customer_id) {
    $customerPoints = $this->loyaltyService->getCustomerPoints(
        (int) $billing->store_id,
        (int) $billing->customer_id
    );

    if ($pointsToRedeem > $customerPoints) {
        abort(response()->json([
            'message' => 'Points redeemed exceed customer available balance.',
        ], 422));
    }

    $activeRule   = $this->loyaltyService->getActiveRule((int) $billing->store_id);
    $pointValue   = (float) ($activeRule?->point_value ?? 1);
    $maxByInvoice = (int) floor((float) $billing->total / $pointValue);

    if ($pointsToRedeem > $maxByInvoice) {
        abort(response()->json([
            'message' => 'Points redeemed would exceed the invoice total.',
        ], 422));
    }
}

// Redeem loyalty points before payment distribution
if ($pointsToRedeem > 0 && $billing->customer_id) {
    try {
        $redemption = $this->loyaltyService->redeemPoints(
            storeId:        (int) $billing->store_id,
            customerId:     (int) $billing->customer_id,
            billingId:      (int) $billing->billing_id,
            pointsToRedeem: $pointsToRedeem,
        );

        $pointsDiscount = (float) $redemption['discount_amount'];

        $billing->update([
            'total'            => max((float) $billing->total - $pointsDiscount, 0),
            'balance_due'      => max((float) $billing->balance_due - $pointsDiscount, 0),
            'points_discount'  => $pointsDiscount,
        ]);

        $billing = $billing->fresh();
        $billing->loadMissing('items.product');
    } catch (\Throwable $e) {
        abort(response()->json(['message' => $e->getMessage()], 422));
    }
}
// After the points redemption block, before $method = $data['payment_method']:

$billing = $billing->fresh();
$billing->loadMissing('items.product');

// Fully covered by loyalty points — mark as paid and skip payment allocation
if ((float) $billing->balance_due <= 0) {
    $billing->update([
        'paid_amount' => (float) $billing->total,
        'balance_due' => 0,
        'status'      => 'paid',
        'is_draft'    => false,
    ]);

    $payment = Payment::create([
        'billing_id'      => $billing->billing_id,
        'receiptnumber'   => $this->documentNumberService->nextNumber($billing->store_id, 'Receipt'),
        'payment_method'  => $data['payment_method'],
        'amount_received' => 0,
        'amount_tendered' => 0,
        'change_returned' => 0,
        'balance_before'  => 0,
        'balance_after'   => 0,
        'payment_date'    => now(),
    ]);

    if (!empty($billing->customer_id)) {
        $freshBalance = round((float) Billing::where('customer_id', $billing->customer_id)
            ->where('status', '!=', 'paid')
            ->where('is_draft', false)
            ->sum('balance_due'), 2);

        Customer::where('customer_id', $billing->customer_id)
            ->update(['current_balance' => $freshBalance]);

        try {
            $this->loyaltyService->earnPoints(
                storeId:    (int) $billing->store_id,
                customerId: (int) $billing->customer_id,
                billingId:  (int) $billing->billing_id,
                saleAmount: (float) $billing->total,
            );
        } catch (\Throwable $e) {
            \Log::warning('Loyalty points earn failed: ' . $e->getMessage());
        }
    }

    return $payment->load(['billing.customer', 'billing.store', 'billing.user']);
}


            $method     = $data['payment_method'];
            $customerId = $billing->customer_id;

            // Isolate debt retrieval strictly to this customer
            if (empty($customerId)) {
                $allCustomerBillings = collect([$billing]);
                $previousDebtsSum    = 0.00;
            } else {
$previousDebtsSum = round((float) Billing::where('customer_id', $customerId)
    ->where('billing_id', '!=', $billing->billing_id)
    ->where('status', '!=', 'paid')
    ->where('is_draft', false) 
    ->sum('balance_due'), 2);

  $allCustomerBillings = Billing::where('customer_id', $customerId)
    ->where('status', '!=', 'paid')
    ->where('is_draft', false) 
    ->lockForUpdate()
    ->get();
            }

            $totalCombinedBalance = round((float) $allCustomerBillings->sum('balance_due'), 2);

            $rawTendered = array_key_exists('amount_tendered', $data)
                ? round((float) $data['amount_tendered'], 2)
                : round((float) $data['amount_received'], 2);

            if ($rawTendered <= 0) {
                abort(response()->json([
                    'message' => 'Amount tendered must be greater than zero.',
                ], 422));
            }

            if ($totalCombinedBalance <= 0) {
                abort(response()->json([
                    'message' => 'This customer has no outstanding balances due.',
                ], 422));
            }

            $moneyLeftToAllocate   = $rawTendered;
            $globalChangeReturned  = 0.00;

            if ($method === 'cash' && $rawTendered > $totalCombinedBalance) {
                $globalChangeReturned = round($rawTendered - $totalCombinedBalance, 2);
            }

            $sortedBillings       = $allCustomerBillings->sortBy('created_at');
            $primaryPaymentRecord = null;

            foreach ($sortedBillings as $currentBill) {
                if ($moneyLeftToAllocate <= 0) {
                    break;
                }

                $billBalanceBefore = round((float) $currentBill->balance_due, 2);
                $paymentToApply    = min($moneyLeftToAllocate, $billBalanceBefore);
                $isCurrentInvoice  = ((int) $currentBill->billing_id === (int) $billing->billing_id);

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

                $paid = round((float) $currentBill->payments()->sum('amount_received'), 2);
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

            // Sync physical current_balance
            if (!empty($customerId)) {
$freshCustomerBalance = round((float) Billing::where('customer_id', $customerId)
    ->where('status', '!=', 'paid')
    ->where('is_draft', false) // ← add this
    ->sum('balance_due'), 2);

                Customer::where('customer_id', $customerId)->update([
                    'current_balance' => $freshCustomerBalance,
                ]);
            }

            $freshPayment = $primaryPaymentRecord->fresh();
            $currentInvoiceBalance = round((float) $billing->balance_due, 2);
            $freshPayment->balance_calculation_label = "+{$currentInvoiceBalance}/+{$previousDebtsSum}";

            // Earn loyalty points
            if (!empty($customerId)) {
                try {
                    $this->loyaltyService->earnPoints(
                        storeId:    (int) $billing->store_id,
                        customerId: (int) $customerId,
                        billingId:  (int) $billing->billing_id,
                        saleAmount: (float) $billing->total,
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Loyalty points earn failed: ' . $e->getMessage());
                }
            }

            // Apply SKU-based Chapa 5 using only qualifying paid SKU lines
            // Excludes free claimed reward lines (unit_price <= 0 or total_amount <= 0)
            if (!empty($customerId)) {
                try {
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
                                storeId:    (int) $billing->store_id,
                                customerId: (int) $customerId,
                                billingId:  (int) $billing->billing_id,
                                sku:        $rule->chapa5_product_sku,
                                itemsBought:(int) $qualifyingQty,
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Chapa 5 apply failed: ' . $e->getMessage());
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
