<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Models\RewardRule;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardRuleController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function index(Request $request): JsonResponse
    {
        $storeId = $request->store_id;

        $rules = RewardRule::where('store_id', $storeId)
            ->orderByDesc('id')
            ->get();

        $activeRule = $this->loyalty->getActiveRule((int) $storeId);

        return response()->json([
            'data'        => $rules,
            'active_rule' => $activeRule,
        ]);
    }

    public function store(Request $request): JsonResponse
{
    if ($error = $this->authorizePermission('stores.manage')) return $error;

    $data = $request->validate([
        'store_id'              => ['required', 'exists:stores,store_id'],
        'rule_name'             => ['required', 'string', 'max:100'],
        'points_per_shilling'   => ['required', 'numeric', 'min:0'],
        'min_spend_required'    => ['required', 'numeric', 'min:0'],
        'point_value'           => ['required', 'numeric', 'min:0'],
        'min_redemption_points' => ['required', 'numeric', 'min:0'],
        'is_active'             => ['boolean'],
        'start_date'            => ['nullable', 'date'],
        'end_date'              => ['nullable', 'date', 'after:start_date'],
        // Chapa 5
        'chapa5_enabled'        => ['boolean'],
        'chapa5_buy_count'      => ['integer', 'min:1'],
        'chapa5_free_count'     => ['integer', 'min:1'],
        'chapa5_label'          => ['string', 'max:50'],
    ]);

    return response()->json([
        'message' => 'Reward rule created successfully.',
        'data'    => RewardRule::create($data),
    ], 201);
}

public function update(Request $request, RewardRule $rewardRule): JsonResponse
{
    if ($error = $this->authorizePermission('stores.manage')) return $error;

    $data = $request->validate([
        'rule_name'             => ['sometimes', 'string', 'max:100'],
        'points_per_shilling'   => ['sometimes', 'numeric', 'min:0'],
        'min_spend_required'    => ['sometimes', 'numeric', 'min:0'],
        'point_value'           => ['sometimes', 'numeric', 'min:0'],
        'min_redemption_points' => ['sometimes', 'numeric', 'min:0'],
        'is_active'             => ['sometimes', 'boolean'],
        'start_date'            => ['nullable', 'date'],
        'end_date'              => ['nullable', 'date'],
        // Chapa 5
        'chapa5_enabled'        => ['sometimes', 'boolean'],
        'chapa5_buy_count'      => ['sometimes', 'integer', 'min:1'],
        'chapa5_free_count'     => ['sometimes', 'integer', 'min:1'],
        'chapa5_label'          => ['sometimes', 'string', 'max:50'],
    ]);

    $rewardRule->update($data);

    return response()->json([
        'message' => 'Reward rule updated successfully.',
        'data'    => $rewardRule->fresh(),
    ]);
}

public function customerLoyalty(Request $request): JsonResponse
{
    $request->validate([
        'store_id'    => ['required', 'exists:stores,store_id'],
        'customer_id' => ['required', 'exists:customers,customer_id'],
    ]);

    $storeId    = (int) $request->store_id;
    $customerId = (int) $request->customer_id;

    $customer    = \App\Models\Customer::find($customerId);
    $rule        = $this->loyalty->getActiveRule($storeId);
    $pointsValue = $rule ? $this->loyalty->pointsToMoney($storeId, $customer->loyalty_points) : 0;

    // Chapa 5 status
    $chapa5Status = null;
    if ($rule && $rule->chapa5_enabled) {
        $buyCount       = $rule->chapa5_buy_count;
        $currentPunches = (int) $customer->punch_card_count;
        $punchesNeeded  = $buyCount - ($currentPunches % $buyCount);

        $chapa5Status = [
            'enabled'         => true,
            'label'           => $rule->chapa5_label,
            'buy_count'       => $buyCount,
            'free_count'      => $rule->chapa5_free_count,
            'current_punches' => $currentPunches,
            'punches_needed'  => $punchesNeeded === $buyCount ? 0 : $punchesNeeded,
            'progress'        => $currentPunches % $buyCount,
            'display'         => ($currentPunches % $buyCount) . ' / ' . $buyCount,
        ];
    }

    $history = \App\Models\LoyaltyTransaction::where('store_id', $storeId)
        ->where('customer_id', $customerId)
        ->orderByDesc('id')
        ->limit(10)
        ->get();

    return response()->json([
        'data' => [
            'loyalty_points'          => $customer->loyalty_points,
            'total_earned_points'     => $customer->total_earned_points,
            'points_value'            => $pointsValue,
            'total_free_items_earned' => $customer->total_free_items_earned,
            'active_rule'             => $rule,
            'chapa5'                  => $chapa5Status,
            'recent_transactions'     => $history,
        ],
    ]);
}
}