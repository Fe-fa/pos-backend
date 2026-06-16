<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\RewardRule;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RewardRuleController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function index(Request $request): JsonResponse
    {
        $storeId = (int) $request->store_id;

        $rules = RewardRule::where('store_id', $storeId)
            ->orderByDesc('id')
            ->get();

        $activeRule = $this->loyalty->getActiveRule($storeId);

        return response()->json([
            'data' => $rules,
            'active_rule' => $activeRule,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.manage')) {
            return $error;
        }

        $data = $request->validate([
            'store_id' => ['required', 'exists:stores,store_id'],
            'rule_name' => ['required', 'string', 'max:100'],
            // Security Fix: Min value changed to 0.0001 to prevent DivisionByZero errors
            'points_per_shilling' => ['required', 'numeric', 'min:0.0001'],
            'min_spend_required' => ['required', 'numeric', 'min:0'],
            'point_value' => ['required', 'numeric', 'min:0.0001'],
            'min_redemption_points' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],

            // Chapa 5
            'chapa5_enabled' => ['boolean'],
            'chapa5_product_sku' => ['nullable', 'string', 'max:100'],
            'chapa5_buy_count' => ['nullable', 'integer', 'min:1'],
            'chapa5_free_count' => ['nullable', 'integer', 'min:1'],
            'chapa5_label' => ['nullable', 'string', 'max:50'],
        ]);

        $this->validateChapa5Payload($data);

        $rule = DB::transaction(function () use ($data) {
            if (!empty($data['is_active'])) {
                RewardRule::where('store_id', $data['store_id'])
                    ->update(['is_active' => false]);
            }

            return RewardRule::create($data);
        });

        return response()->json([
            'message' => 'Reward rule created successfully.',
            'data' => $rule,
        ], 201);
    }

    public function update(Request $request, RewardRule $rewardRule): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.manage')) {
            return $error;
        }

        // Security Fix: Prevent cross-store parameter tampering
        $storeId = (int) $request->input('store_id', $rewardRule->store_id);
        if ($rewardRule->store_id !== $storeId) {
            return response()->json(['error' => 'Unauthorized cross-store access attempt.'], 403);
        }

        $data = $request->validate([
            'rule_name' => ['sometimes', 'string', 'max:100'],
            // Security Fix: Protect update route from 0 assignments causing system crash
            'points_per_shilling' => ['sometimes', 'numeric', 'min:0.0001'],
            'min_spend_required' => ['sometimes', 'numeric', 'min:0'],
            'point_value' => ['sometimes', 'numeric', 'min:0.0001'],
            'min_redemption_points' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],

            // Chapa 5
            'chapa5_enabled' => ['sometimes', 'boolean'],
            'chapa5_product_sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'chapa5_buy_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'chapa5_free_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'chapa5_label' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $merged = array_merge($rewardRule->toArray(), $data);
        $this->validateChapa5Payload($merged);

        DB::transaction(function () use ($data, $rewardRule) {
            if (($data['is_active'] ?? false) === true) {
                RewardRule::where('store_id', $rewardRule->store_id)
                    ->where('id', '!=', $rewardRule->id)
                    ->update(['is_active' => false]);
            }

            $rewardRule->update($data);
        });

        return response()->json([
            'message' => 'Reward rule updated successfully.',
            'data' => $rewardRule->fresh(),
        ]);
    }

    public function destroy(Request $request, RewardRule $rewardRule): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.manage')) {
            return $error;
        }

        // Security Fix: Explicit validation of scope ownership matching context 
        $storeId = (int) $request->store_id;
        if ($rewardRule->store_id !== $storeId) {
            return response()->json(['error' => 'Action violates tenant scope boundary requirements.'], 403);
        }

        $rewardRule->delete();

        return response()->json([
            'message' => 'Reward rule deleted successfully.',
        ]);
    }

    public function customerLoyalty(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'exists:stores,store_id'],
            'customer_id' => ['required', 'exists:customers,customer_id'],
        ]);

        $storeId = (int) $request->store_id;
        $customerId = (int) $request->customer_id;

        $customer = Customer::where('customer_id', $customerId)->firstOrFail();
        $rule = $this->loyalty->getActiveRule($storeId);

        $pointsValue = $rule
            ? $this->loyalty->pointsToMoney($storeId, (int) $customer->loyalty_points)
            : 0.0;

        $chapa5Status = null;

        if ($rule && $rule->chapa5_enabled) {
            $buyCount = max(1, (int) $rule->chapa5_buy_count);
            $currentPunches = (int) ($customer->punch_card_count ?? 0);
            $progress = $currentPunches % $buyCount;
            $punchesNeeded = $progress === 0 ? $buyCount : ($buyCount - $progress);

            $chapa5Status = [
                'enabled' => true,
                'label' => $rule->chapa5_label,
                'product_sku' => $rule->chapa5_product_sku,
                'buy_count' => $buyCount,
                'free_count' => (int) $rule->chapa5_free_count,
                'current_punches' => $currentPunches,
                'punches_needed' => $punchesNeeded,
                'progress' => $progress,
                'display' => $progress . ' / ' . $buyCount,
            ];
        }

        // Security Performance Optimization: Constrain memory indexing limits
        $history = LoyaltyTransaction::where('store_id', $storeId)
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'loyalty_points' => (int) $customer->loyalty_points,
                'total_earned_points' => (int) $customer->total_earned_points,
                'points_value' => $pointsValue,
                'total_free_items_earned' => (int) $customer->total_free_items_earned,
                'active_rule' => $rule,
                'chapa5' => $chapa5Status,
                'recent_transactions' => $history,
            ],
        ]);
    }

    private function validateChapa5Payload(array $data): void
    {
        if (empty($data['chapa5_enabled'])) {
            return;
        }

        $errors = [];

        if (blank($data['chapa5_product_sku'] ?? null)) {
            $errors['chapa5_product_sku'] = ['Chapa 5 product SKU is required when the promotion is enabled.'];
        }

        if (empty($data['chapa5_buy_count'])) {
            $errors['chapa5_buy_count'] = ['Chapa 5 buy count is required when the promotion is enabled.'];
        }

        if (empty($data['chapa5_free_count'])) {
            $errors['chapa5_free_count'] = ['Chapa 5 free count is required when the promotion is enabled.'];
        }

        if (blank($data['chapa5_label'] ?? null)) {
            $errors['chapa5_label'] = ['Chapa 5 label is required when the promotion is enabled.'];
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}