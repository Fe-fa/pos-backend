<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

trait AuthorizesPermission
{
    protected function authorizePermission(string $permission): ?JsonResponse
    {
        $user = request()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isAdmin()) {
            return null;
        }

        if (!$user->can($permission)) {
            return response()->json([
                'message' => "You do not have permission to perform this action. Required: {$permission}",
            ], 403);
        }

        return null;
    }
}