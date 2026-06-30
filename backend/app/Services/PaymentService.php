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
    // Always re-fetch from DB — never trust client-sent value
    $customerPoints = $this->loyaltyService->getCustomerPoints(
        (int) $billing->store_id,
        (int) $billing->customer_id
    );

    // Silently clamp instead of aborting — frontend may send slightly stale value
    $pointsToRedeem = min($pointsToRedeem, $customerPoints);

    if ($pointsToRedeem <= 0) {
        $pointsToRedeem = 0;
    }

    if ($pointsToRedeem > 0) {
        $activeRule   = $this->loyaltyService->getActiveRule((int) $billing->store_id);
        $pointValue   = (float) ($activeRule?->point_value ?? 1);
        $maxByInvoice = (int) floor((float) $billing->total / $pointValue);

        // Clamp to invoice ceiling too
        $pointsToRedeem = min($pointsToRedeem, $maxByInvoice);
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
    public function chargeCart(User $user, array $data): array
{
    $this->billingService->authorizeStoreAccess($user, $data['store_id']);

    return DB::transaction(function () use ($user, $data) {
        $billing = Billing::create([
            'store_id'           => $data['store_id'],
            'customer_id'        => $data['customer_id'] ?? null,
            'user_id'            => $user->user_id,
            'invnumber'          => null,
            'status'             => 'unpaid',
            'subtotal'           => 0,
            'vat_amount'         => 0,
            'total'              => 0,
            'paid_amount'        => 0,
            'balance_due'        => 0,
            'is_draft'           => true,
            'billing_date'       => now(),
            'notes'              => $data['notes'] ?? null,
            'fulfillment_status' => 'pending',
            'fulfillment_type'   => 'walk_in_counter',
        ]);

        foreach ($data['items'] as $item) {
            $qty = (int) $item['quantity'];
            $unitPrice = round((float) $item['price'], 2);
            $vatRate = (float) ($item['vat_rate'] ?? 16);

$totalAmount = $qty * $unitPrice;
$lineSubtotal = $totalAmount / (1 + ($vatRate / 100));
$vatAmount = $totalAmount - $lineSubtotal;

$billing->items()->create([
    'product_id'    => (int) $item['product_id'],
    'quantity'      => $qty,
    'unit_price'    => $unitPrice,
    'vat_rate'      => $vatRate,
    'line_subtotal' => round($lineSubtotal, 2),
    'vat_amount'    => round($vatAmount, 2),
    'total_amount'  => round($totalAmount, 2),
]);
        }

        $billing = $this->billingService->recalculateTotals($billing->fresh());

        $payment = $this->charge($billing, $user, [
            'payment_method'  => $data['payment_method'],
            'amount_received' => (float) ($data['amount_received'] ?? $data['amount_tendered'] ?? 0),
            'amount_tendered' => (float) ($data['amount_tendered'] ?? 0),
            'points_redeemed' => (int) ($data['points_redeemed'] ?? 0),
            'mpesa_phone'     => $data['mpesa_phone'] ?? null,
            'mpesa_code'      => $data['mpesa_code'] ?? null,
            'card_reference'  => $data['card_reference'] ?? null,
            'card_holder'     => $data['card_holder'] ?? null,
        ]);

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

}
