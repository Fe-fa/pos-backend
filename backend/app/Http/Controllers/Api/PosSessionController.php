<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosSessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $storeId = (int) $data['store_id'];

        $this->assertStoreAccess($user, $storeId);

        $session = PosSession::query()
            ->where('user_id', $user->user_id)
            ->where('store_id', $storeId)
            ->first();

        return response()->json([
            'data' => $session ? $this->transform($session) : null,
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'billing_id' => ['nullable', 'integer'],
            'selected_customer_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'local_items' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $storeId = (int) $data['store_id'];

        $this->assertStoreAccess($user, $storeId);

        $session = PosSession::query()->updateOrCreate(
            [
                'user_id' => $user->user_id,
                'store_id' => $storeId,
            ],
            [
                'billing_id' => $data['billing_id'] ?? null,
                'selected_customer_id' => $data['selected_customer_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'local_items' => $data['local_items'] ?? [],
            ]
        );

        return response()->json([
            'message' => 'POS session saved.',
            'data' => $this->transform($session->fresh()),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $storeId = (int) $data['store_id'];

        $this->assertStoreAccess($user, $storeId);

        PosSession::query()
            ->where('user_id', $user->user_id)
            ->where('store_id', $storeId)
            ->delete();

        return response()->json([
            'message' => 'POS session cleared.',
        ]);
    }

    private function assertStoreAccess(User $user, int $storeId): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $hasAccess =
            (int) $user->default_store_id === $storeId ||
            $user->stores()->where('stores.store_id', $storeId)->exists();

        abort_unless($hasAccess, 403, 'You do not have access to this store.');
    }

    private function transform(PosSession $session): array
    {
        return [
            'id' => $session->id,
            'user_id' => $session->user_id,
            'store_id' => $session->store_id,
            'billing_id' => $session->billing_id,
            'selected_customer_id' => $session->selected_customer_id,
            'notes' => $session->notes,
            'local_items' => $session->local_items ?? [],
            'updated_at' => optional($session->updated_at)?->toISOString(),
            'created_at' => optional($session->created_at)?->toISOString(),
        ];
    }
}
