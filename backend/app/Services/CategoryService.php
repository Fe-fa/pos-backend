<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;


class CategoryService
{
    private function allowedStoreIds(User $user)
    {
        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->unique()
            ->values();
    }

    private function authorizeStoreAccess(User $user, int|string|null $storeId): void
    {
        if (!$storeId || $user->isAdmin()) {
            return;
        }

        $allowed = $this->allowedStoreIds($user)
            ->map(fn ($id) => (string) $id)
            ->all();

        if (!in_array((string) $storeId, $allowed, true)) {
            abort(response()->json([
                'message' => 'You are not allowed to access this store.',
            ], 403));
        }
    }

    public function paginate(User $user, array $filters = []): Paginator
    {
        $perPage = (int) ($filters['per_page'] ?? 12);

        $query = Category::query()
            ->withCount('products')
            ->orderBy('category_name');

        if (!$user->isAdmin()) {
            $query->whereIn('store_id', $this->allowedStoreIds($user));
        }

        if (!empty($filters['store_id'])) {
            $this->authorizeStoreAccess($user, $filters['store_id']);
            $query->where('store_id', $filters['store_id']);
        }
        
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where('category_name', 'like', "%{$search}%");
        }

        return $query->simplePaginate($perPage);
        
    }

    public function create(User $user, array $data): Category
    {
        $this->authorizeStoreAccess($user, $data['store_id']);

        return Category::create([
            'store_id' => $data['store_id'],
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

        $category->update([
            'store_id' => $data['store_id'] ?? $category->store_id,
            'category_name' => $data['category_name'],
        ]);

        return $category->fresh()->loadCount('products');
    }

    public function delete(User $user, Category $category): void
    {
        $this->authorizeStoreAccess($user, $category->store_id);

        if ($category->products()->exists()) {
            abort(response()->json([
                'message' => 'Cannot delete category because it has linked products.',
            ], 422));
        }

        $category->delete();
    }
}
