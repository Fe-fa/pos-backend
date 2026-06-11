<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class CategoryService
{
    public function allowedStoreIds(User $user): Collection
    {
        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function authorizeStoreAccess(User $user, int|string|null $storeId): void
    {
        if (!$storeId || $user->isAdmin()) {
            return;
        }

        $allowed = $this->allowedStoreIds($user)->all();

        if (!in_array((int) $storeId, $allowed, true)) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'You are not allowed to access this store.',
                ], 403)
            );
        }
    }

    public function create(User $user, array $data): Category
    {
        $this->authorizeStoreAccess($user, $data['store_id']);

        return Category::create([
            'store_id'      => $data['store_id'],
            'category_name' => $data['category_name'],
        ])->loadCount('products');
    }

    public function show(Category $category, User $user): Category
    {
        $this->authorizeStoreAccess($user, $category->store_id);

        return $category->loadCount('products');
    }

    public function update(User $user, Category $category, array $data): Category
    {
        $this->authorizeStoreAccess($user, $category->store_id);

        if (isset($data['store_id']) && (int) $data['store_id'] !== (int) $category->store_id) {
            $this->authorizeStoreAccess($user, $data['store_id']);
        }

        $category->update([
            'store_id'      => $data['store_id'] ?? $category->store_id,
            'category_name' => $data['category_name'],
        ]);

        return $category->fresh()->loadCount('products');
    }

    public function delete(User $user, Category $category): void
    {
        $this->authorizeStoreAccess($user, $category->store_id);

        if ($category->products()->exists()) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Cannot delete category because it has linked products.',
                ], 422)
            );
        }

        $category->delete();
    }
}