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

    public function getCustomerPoints(int $storeId, int $customerId): int
    {
        $customer = Customer::find($customerId);
        return (int) ($customer?->loyalty_points ?? 0);
    }

    /**
     * Calculate points earned based on the "Shillings per point" rule.
     * Enforces the threshold and uses division instead of multiplication.
     */
    public function calculatePointsEarned(int $storeId, float $amount): int
    {
        $rule = $this->getActiveRule($storeId);

        // Explicit boundary gate checking against minimum purchase threshold rules
        if (!$rule || $amount < $rule->min_spend_required || $rule->points_per_shilling <= 0) {
            return 0;
        }

        // Changed from multiplication to division to support your Form UI layout
        // Example: floor(150 Kes / 100.0000 Shillings per point) = 1 Point
        return (int) floor($amount / $rule->points_per_shilling);
    }

    public function pointsToMoney(int $storeId, int $points): float
    {
        $rule = $this->getActiveRule($storeId);

        if (!$rule) {
            return 0.0;
        }

        return round($points * $rule->point_value, 2);
    }

    public function moneyToPoints(int $storeId, float $amount): int
    {
        $rule = $this->getActiveRule($storeId);

        if (!$rule || $rule->point_value <= 0) {
            return 0;
        }

        return (int) floor($amount / $rule->point_value);
    }

    private function normalizeSku(?string $sku): string
    {
        return strtolower(trim((string) $sku));
    }

    private function chapaRuleMatchesSku(?RewardRule $rule, ?string $sku): bool
    {
        if (!$rule || !$rule->chapa5_enabled) {
            return false;
        }

        $configuredSku = $this->normalizeSku($rule->chapa5_product_sku);
        $candidateSku  = $this->normalizeSku($sku);

        return $configuredSku !== '' && $candidateSku !== '' && $configuredSku === $candidateSku;
    }

    /**
     * Preview Chapa 5 qualification for a specific SKU purchase.
     */
    public function checkChapa5(
        int $storeId,
        int $customerId,
        ?string $sku,
        int $itemsBought
    ): array {
        $customer = Customer::find($customerId);

        if (!$customer) {
            return [
                'qualifies' => false,
                'free_items' => 0,
                'punches_after' => 0,
                'total_punches' => 0,
                'buy_count' => 0,
                'free_count' => 0,
                'current_punches' => 0,
                'label' => null,
                'product_sku' => null,
            ];
        }

        $rule = $this->getActiveRule($storeId);
        $currentPunches = (int) ($customer->punch_card_count ?? 0);

        if (!$this->chapaRuleMatchesSku($rule, $sku) || $itemsBought <= 0) {
            return [
                'qualifies' => false,
                'free_items' => 0,
                'punches_after' => $currentPunches,
                'total_punches' => $currentPunches,
                'buy_count' => (int) ($rule?->chapa5_buy_count ?? 0),
                'free_count' => (int) ($rule?->chapa5_free_count ?? 0),
                'current_punches' => $currentPunches,
                'label' => $rule?->chapa5_label,
                'product_sku' => $rule?->chapa5_product_sku,
            ];
        }

        $buyCount  = max(1, (int) $rule->chapa5_buy_count);
        $freeCount = max(1, (int) $rule->chapa5_free_count);

        $newPunches = $currentPunches + max(0, $itemsBought);

        $previousCycles = intdiv($currentPunches, $buyCount);
        $newCycles      = intdiv($newPunches, $buyCount);

        $newFreeItemsEarned = max(($newCycles - $previousCycles) * $freeCount, 0);
        $punchesAfter       = $newPunches % $buyCount;

        return [
            'qualifies' => $newFreeItemsEarned > 0,
            'free_items' => $newFreeItemsEarned,
            'punches_after' => $punchesAfter,
            'total_punches' => $newPunches,
            'buy_count' => $buyCount,
            'free_count' => $freeCount,
            'current_punches' => $currentPunches,
            'label' => $rule->chapa5_label,
            'product_sku' => $rule->chapa5_product_sku,
        ];
    }

    
public function applyChapa5(
    int $storeId,
    int $customerId,
    int $billingId,
    ?string $sku,
    int $itemsBought
): array {
    $rule = $this->getActiveRule($storeId);

    if (!$this->chapaRuleMatchesSku($rule, $sku) || $itemsBought <= 0) {
        return [];
    }

    return DB::transaction(function () use ($storeId, $customerId, $billingId, $itemsBought, $rule, $sku) {
        $customer = Customer::where('customer_id', $customerId)
            ->lockForUpdate()
            ->first();

        if (!$customer) {
            return [];
        }

        $currentPunches = (int) ($customer->punch_card_count ?? 0);
        $buyCount       = max(1, (int) $rule->chapa5_buy_count);
        $freeCount      = max(1, (int) $rule->chapa5_free_count);

        $newPunches = $currentPunches + max(0, $itemsBought);

        $previousCycles = intdiv($currentPunches, $buyCount);
        $newCycles      = intdiv($newPunches, $buyCount);

        $cyclesCompleted    = max($newCycles - $previousCycles, 0);

        $newFreeItemsEarned = $cyclesCompleted > 0 ? $freeCount : 0;

        $punchesAfter = $newPunches % $buyCount;

        $customer->update([
            'punch_card_count'        => $punchesAfter,
            'total_free_items_earned' => (int) $customer->total_free_items_earned + $newFreeItemsEarned,
        ]);

        if ($newFreeItemsEarned > 0) {
            LoyaltyTransaction::create([
                'store_id'         => $storeId,
                'customer_id'      => $customerId,
                'billing_id'       => $billingId,
                'transaction_type' => 'earned',
                'points'           => 0,
                'amount_equivalent'=> 0,
                'notes'            => "{$rule->chapa5_label} ({$sku}): {$newFreeItemsEarned} free item(s) earned",
            ]);
        }

        return [
            'qualifies'       => $newFreeItemsEarned > 0,
            'free_items'      => $newFreeItemsEarned,
            'punches_after'   => $punchesAfter,
            'total_punches'   => $newPunches,
            'buy_count'       => $buyCount,
            'free_count'      => $freeCount,
            'current_punches' => $currentPunches,
            'label'           => $rule->chapa5_label,
            'product_sku'     => $rule->chapa5_product_sku,
        ];
    });
}

    /**
     * Commit newly earned general loyalty points onto customer record profiles.
     */
    public function earnPoints(
        int $storeId,
        int $customerId,
        int $billingId,
        float $saleAmount
    ): ?LoyaltyTransaction {
        $points = $this->calculatePointsEarned($storeId, $saleAmount);

        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($storeId, $customerId, $billingId, $points) {
            // Security: Use row-level write allocations isolation patterns via lockForUpdate
            $customer = Customer::where('customer_id', $customerId)->lockForUpdate()->first();
            
            if (!$customer) {
                return null;
            }

            $customer->increment('loyalty_points', $points);
            $customer->increment('total_earned_points', $points);

            return LoyaltyTransaction::create([
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'billing_id' => $billingId,
                'transaction_type' => 'earned',
                'points' => $points,
                'amount_equivalent' => $this->pointsToMoney($storeId, $points),
                'notes' => "Earned from billing #{$billingId}",
            ]);
        });
    }

    /**
     * Deduct currency points balance upon cashier confirmation actions.
     */
    public function redeemPoints(
        int $storeId,
        int $customerId,
        int $billingId,
        int $pointsToRedeem
    ): array {
        $rule = $this->getActiveRule($storeId);

        if (!$rule) {
            throw new \Exception('No active reward rule found for this store.');
        }

        if ($pointsToRedeem < $rule->min_redemption_points) {
            throw new \Exception("Minimum redemption is {$rule->min_redemption_points} points.");
        }

        $discount = $this->pointsToMoney($storeId, $pointsToRedeem);

        DB::transaction(function () use ($storeId, $customerId, $billingId, $pointsToRedeem, $discount) {
            // Security: Enforced pessimistic lock alignment pattern
            $customer = Customer::where('customer_id', $customerId)->lockForUpdate()->first();

            if (!$customer) {
                throw new \Exception('Customer not found.');
            }

            if ($customer->loyalty_points < $pointsToRedeem) {
                throw new \Exception("Insufficient points. Available: {$customer->loyalty_points}");
            }

            $customer->decrement('loyalty_points', $pointsToRedeem);

            LoyaltyTransaction::create([
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'billing_id' => $billingId,
                'transaction_type' => 'redeemed',
                'points' => -$pointsToRedeem,
                'amount_equivalent' => $discount,
                'notes' => "Redeemed for KES {$discount} discount",
            ]);
        });

        return [
            'points_redeemed' => $pointsToRedeem,
            'discount_amount' => $discount,
        ];
    }

    /**
     * Reverse calculations during returns or cancellations.
     */
    public function reversePoints(int $billingId): void
    {
        $transactions = LoyaltyTransaction::where('billing_id', $billingId)->get();

        DB::transaction(function () use ($transactions) {
            foreach ($transactions as $tx) {
                // Security protection logic isolation step
                $customer = Customer::where('customer_id', $tx->customer_id)->lockForUpdate()->first();
                
                if (!$customer) {
                    continue;
                }

                if ($tx->transaction_type === 'earned' && $tx->points > 0) {
                    $customer->decrement('loyalty_points', $tx->points);

                    LoyaltyTransaction::create([
                        'store_id' => $tx->store_id,
                        'customer_id' => $tx->customer_id,
                        'billing_id' => $tx->billing_id,
                        'transaction_type' => 'refund_deduction',
                        'points' => -$tx->points,
                        'amount_equivalent' => $tx->amount_equivalent,
                        'notes' => "Reversed from billing #{$tx->billing_id}",
                    ]);
                }

                if ($tx->transaction_type === 'redeemed') {
                    $customer->increment('loyalty_points', abs($tx->points));

                    LoyaltyTransaction::create([
                        'store_id' => $tx->store_id,
                        'customer_id' => $tx->customer_id,
                        'billing_id' => $tx->billing_id,
                        'transaction_type' => 'earned',
                        'points' => abs($tx->points),
                        'amount_equivalent' => $tx->amount_equivalent,
                        'notes' => "Refund restored from billing #{$tx->billing_id}",
                    ]);
                }
            }
        });
    }
}