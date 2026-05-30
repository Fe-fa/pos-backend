<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService
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

            // Finalize billing once only.
            // This is where FIFO stock deduction will happen via BillingService.
            $billing = $this->billingService->finalizeIfNeeded($billing, $user);

            // Re-lock and refresh after finalization
            $billing = Billing::query()
                ->whereKey($billing->billing_id)
                ->lockForUpdate()
                ->firstOrFail();

            $billing = $this->billingService->recalculateTotals($billing);

            $method = $data['payment_method'];
            $amountReceived = round((float) $data['amount_received'], 2);
            $amountTendered = array_key_exists('amount_tendered', $data)
                ? round((float) $data['amount_tendered'], 2)
                : $amountReceived;

            $balanceBefore = round((float) $billing->balance_due, 2);

            if ($amountReceived <= 0) {
                abort(response()->json([
                    'message' => 'Amount received must be greater than zero.',
                ], 422));
            }

            if ($balanceBefore <= 0) {
                abort(response()->json([
                    'message' => 'This billing is already fully paid.',
                ], 422));
            }

            if ($amountReceived > $balanceBefore) {
                abort(response()->json([
                    'message' => 'Amount received cannot exceed the outstanding balance.',
                    'balance_due' => $balanceBefore,
                ], 422));
            }

            $changeReturned = 0;

            if ($method === 'cash') {
                if ($amountTendered < $amountReceived) {
                    abort(response()->json([
                        'message' => 'Cash tendered cannot be less than amount received.',
                    ], 422));
                }

                $changeReturned = round(max($amountTendered - $amountReceived, 0), 2);
            } else {
                // For non-cash methods, tendered should match received
                $amountTendered = $amountReceived;
            }

            $payment = Payment::create([
                'billing_id'       => $billing->billing_id,
                'receiptnumber'    => $this->documentNumberService->nextNumber($billing->store_id, 'Receipt'),
                'payment_method'   => $method,
                'amount_received'  => $amountReceived,
                'amount_tendered'  => $amountTendered,
                'change_returned'  => $changeReturned,
                'balance_before'   => $balanceBefore,
                'balance_after'    => round(max($balanceBefore - $amountReceived, 0), 2),
                'payment_date'     => now(),
            ]);

            $paid = round((float) $billing->payments()->sum('amount_received'), 2);
            $balance = round(max((float) $billing->total - $paid, 0), 2);

            $billing->update([
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
                    'billing_uuid'   => $billing->uuid,
                    'billing_id'     => $billing->billing_id,
                    'invoice_number' => $billing->invnumber,
                ],
                $billing->store_id
            );

            return $payment->fresh()->load([
                'billing.customer',
                'billing.store',
                'billing.user',
            ]);
        });
    }
}
