<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureStoreAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $storeId = $this->resolveStoreId($request);

        if (! $storeId) {
            return new JsonResponse(['message' => 'A valid store_id is required.'], 400);
        }

        if ($user->isAdmin() || $user->can('stores.manage')) {
            $request->attributes->set('resolved_store_id', $storeId);
            return $next($request);
        }

        $hasAccess = ((int) $user->default_store_id === (int) $storeId)
            || $user->stores()->where('stores.store_id', $storeId)->exists();

        if (! $hasAccess) {
            return new JsonResponse(['message' => 'You do not have access to this store.'], 403);
        }

        $request->attributes->set('resolved_store_id', $storeId);

        return $next($request);
    }

    private function resolveStoreId(Request $request): ?int
    {
        $candidates = [
            $request->route('store_id'),
            $request->route('store'),
            $request->input('store_id'),
            $request->header('X-Store-Id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_object($candidate) && isset($candidate->store_id)) {
                return (int) $candidate->store_id;
            }

            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        return null;
    }
}
