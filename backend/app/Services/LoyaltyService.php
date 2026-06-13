<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\RewardRule;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    public function getActiveRule(int $storeId): ?RewardRule
    {
        return RewardRule::activeForStore($storeId)->first();
    }

    public function calculatePointsEarned(int $storeId, float $amount): int
    {
        $rule = $this->getActiveRule($storeId);
        if (!$rule || $amount < $rule->min_spend_required) return 0;
        return (int) floor($amount * $rule->points_per_shilling);
    }

    public function pointsToMoney(int $storeId, int $points): float
    {
        $rule = $this->getActiveRule($storeId);
        if (!$rule) return 0.0;
        return round($points * $rule->point_value, 2);
    }

    public function moneyToPoints(int $storeId, float $amount): int
    {
        $rule = $this->getActiveRule($storeId);
        if (!$rule || $rule->point_value <= 0) return 0;
        return (int) floor($amount / $rule->point_value);
    }

    // ── Chapa 5 ──────────────────────────────────────────────────────────

    /**
     * Check if customer qualifies for a free item and how many free items
     * they would get after this purchase.
     *
     * Returns: ['qualifies' => bool, 'free_items' => int, 'punches_after' => int]
     */
    public function checkChapa5(int $storeId, int $customerId, int $itemsBought): array
    {
        $rule = $this->getActiveRule($storeId);

        if (!$rule || !$rule->chapa5_enabled) {
            return ['qualifies' => false, 'free_items' => 0, 'punches_after' => 0];
        }

        $customer   = Customer::find($customerId);
        $buyCount   = $rule->chapa5_buy_count;
        $freeCount  = $rule->chapa5_free_count;

        $currentPunches = (int) ($customer->punch_card_count ?? 0);
        $newPunches     = $currentPunches + $itemsBought;

        $freeItems   = (int) floor($newPunches / $buyCount) * $freeCount;
        $punchesLeft = $newPunches % $buyCount;

        $previousFreeItems = (int) floor($currentPunches / $buyCount) * $freeCount;
        $newFreeItemsEarned = $freeItems - $previousFreeItems;

        return [
            'qualifies'         => $newFreeItemsEarned > 0,
            'free_items'        => $newFreeItemsEarned,
            'punches_after'     => $punchesLeft,
            'total_punches'     => $newPunches,
            'buy_count'         => $buyCount,
            'current_punches'   => $currentPunches,
            'label'             => $rule->chapa5_label,
        ];
    }

    /**
     * Apply Chapa 5 — increment punch card, log free items earned.
     * Called after a successful payment.
     */
    public function applyChapa5(
        int $storeId,
        int $customerId,
        int $billingId,
        int $itemsBought
    ): array {
        $rule = $this->getActiveRule($storeId);
        if (!$rule || !$rule->chapa5_enabled) return [];

        $check = $this->checkChapa5($storeId, $customerId, $itemsBought);

        DB::transaction(function () use ($storeId, $customerId, $billingId, $itemsBought, $check, $rule) {
            Customer::where('customer_id', $customerId)
                ->increment('punch_card_count', $itemsBought);

            // Reset punch card count if they completed a cycle
            if ($check['qualifies']) {
                Customer::where('customer_id', $customerId)
                    ->increment('total_free_items_earned', $check['free_items']);

                // Reset punches to remainder
                Customer::where('customer_id', $customerId)
                    ->update(['punch_card_count' => $check['punches_after']]);

                LoyaltyTransaction::create([
                    'store_id'          => $storeId,
                    'customer_id'       => $customerId,
                    'billing_id'        => $billingId,
                    'transaction_type'  => 'earned',
                    'points'            => 0,
                    'amount_equivalent' => 0,
                    'notes'             => "{$rule->chapa5_label}: {$check['free_items']} free item(s) earned",
                ]);
            }
        });

        return $check;
    }

    // ── Points ───────────────────────────────────────────────────────────

    public function earnPoints(
        int   $storeId,
        int   $customerId,
        int   $billingId,
        float $saleAmount
    ): ?LoyaltyTransaction {
        $points = $this->calculatePointsEarned($storeId, $saleAmount);
        if ($points <= 0) return null;

        return DB::transaction(function () use ($storeId, $customerId, $billingId, $points) {
            Customer::where('customer_id', $customerId)->increment('loyalty_points', $points);
            Customer::where('customer_id', $customerId)->increment('total_earned_points', $points);

            return LoyaltyTransaction::create([
                'store_id'          => $storeId,
                'customer_id'       => $customerId,
                'billing_id'        => $billingId,
                'transaction_type'  => 'earned',
                'points'            => $points,
                'amount_equivalent' => $this->pointsToMoney($storeId, $points),
                'notes'             => "Earned from billing #{$billingId}",
            ]);
        });
    }

    public function redeemPoints(
        int $storeId,
        int $customerId,
        int $billingId,
        int $pointsToRedeem
    ): array {
        $customer = Customer::find($customerId);
        if (!$customer) throw new \Exception('Customer not found.');

        $rule = $this->getActiveRule($storeId);
        if (!$rule) throw new \Exception('No active reward rule found for this store.');

        if ($pointsToRedeem < $rule->min_redemption_points) {
            throw new \Exception("Minimum redemption is {$rule->min_redemption_points} points.");
        }

        if ($customer->loyalty_points < $pointsToRedeem) {
            throw new \Exception("Insufficient points. Available: {$customer->loyalty_points}");
        }

        $discount = $this->pointsToMoney($storeId, $pointsToRedeem);

        DB::transaction(function () use ($storeId, $customerId, $billingId, $pointsToRedeem, $discount) {
            Customer::where('customer_id', $customerId)->decrement('loyalty_points', $pointsToRedeem);

            LoyaltyTransaction::create([
                'store_id'          => $storeId,
                'customer_id'       => $customerId,
                'billing_id'        => $billingId,
                'transaction_type'  => 'redeemed',
                'points'            => -$pointsToRedeem,
                'amount_equivalent' => $discount,
                'notes'             => "Redeemed for KES {$discount} discount",
            ]);
        });

        return ['points_redeemed' => $pointsToRedeem, 'discount_amount' => $discount];
    }

    public function reversePoints(int $billingId): void
    {
        $transactions = LoyaltyTransaction::where('billing_id', $billingId)->get();

        DB::transaction(function () use ($transactions) {
            foreach ($transactions as $tx) {
                if ($tx->transaction_type === 'earned' && $tx->points > 0) {
                    Customer::where('customer_id', $tx->customer_id)
                        ->decrement('loyalty_points', $tx->points);

                    LoyaltyTransaction::create([
                        'store_id'          => $tx->store_id,
                        'customer_id'       => $tx->customer_id,
                        'billing_id'        => $tx->billing_id,
                        'transaction_type'  => 'refund_deduction',
                        'points'            => -$tx->points,
                        'amount_equivalent' => $tx->amount_equivalent,
                        'notes'             => "Reversed from billing #{$tx->billing_id}",
                    ]);
                }

                if ($tx->transaction_type === 'redeemed') {
                    Customer::where('customer_id', $tx->customer_id)
                        ->increment('loyalty_points', abs($tx->points));

                    LoyaltyTransaction::create([
                        'store_id'          => $tx->store_id,
                        'customer_id'       => $tx->customer_id,
                        'billing_id'        => $tx->billing_id,
                        'transaction_type'  => 'earned',
                        'points'            => abs($tx->points),
                        'amount_equivalent' => $tx->amount_equivalent,
                        'notes'             => "Refund restored from billing #{$tx->billing_id}",
                    ]);
                }
            }
        });
    }
}