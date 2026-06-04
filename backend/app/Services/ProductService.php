<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductService
{
    // Changed to public so the Controller can read it for filtering
    public function allowedStoreIds(User $user)
    {
        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->unique()
            ->values();
    }

    // Changed to public so the Controller can check store access rights
    public function authorizeStoreAccess(User $user, int|string|null $storeId): void
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

    public function create(User $user, array $data): Product
    {
        $this->authorizeStoreAccess($user, $data['store_id']);

        $category = Category::query()->findOrFail($data['category_id']);

        if ((string) $category->store_id !== (string) $data['store_id']) {
            abort(response()->json([
                'message' => 'Selected category does not belong to the selected store.',
            ], 422));
        }

        $imageValue = $this->resolveImageValue($data, null);

        $productData = [
            'store_id'     => $data['store_id'],
            'category_id'  => $data['category_id'],
            'sku'          => $data['sku'],
            'product_name' => $data['product_name'],
            'price'        => $data['price'],
            'cost_price'   => $data['cost_price'],
            'vat_rate'     => $data['vat_rate'] ?? 0,
            'image_url'    => $imageValue,
            'is_active'    => isset($data['is_active'])
                ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN)
                : true,
        ];

        return Product::create($productData)
            ->load('category')
            ->loadSum('inventories as total_stock', 'quantity');
    }

    public function show(User $user, Product $product): Product
    {
        $this->authorizeStoreAccess($user, $product->store_id);

        return $product->load([
            'category',
            'inventories' => fn ($query) => $query
                ->with('store')
                ->orderBy('created_at')
                ->orderBy('inventory_id'),
        ])->loadSum('inventories as total_stock', 'quantity');
    }

    public function update(User $user, Product $product, array $data): Product
    {
        $this->authorizeStoreAccess($user, $product->store_id);

        $storeId = $data['store_id'] ?? $product->store_id;
        $this->authorizeStoreAccess($user, $storeId);

        if (!empty($data['category_id'])) {
            $category = Category::query()->findOrFail($data['category_id']);

            if ((string) $category->store_id !== (string) $storeId) {
                abort(response()->json([
                    'message' => 'Selected category does not belong to the selected store.',
                ], 422));
            }
        }

        $updateData = [
            'store_id'     => $storeId,
            'category_id'  => $data['category_id'] ?? $product->category_id,
            'sku'          => $data['sku'] ?? $product->sku,
            'product_name' => $data['product_name'] ?? $product->product_name,
            'price'        => $data['price'] ?? $product->price,
            'cost_price'   => $data['cost_price'] ?? $product->cost_price,
            'vat_rate'     => array_key_exists('vat_rate', $data) ? $data['vat_rate'] : $product->vat_rate,
            'is_active'    => array_key_exists('is_active', $data)
                ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN)
                : $product->is_active,
        ];

        if ($this->shouldUpdateImage($data)) {
            $previous = $product->getRawOriginal('image_url');

            if ($previous && !filter_var($previous, FILTER_VALIDATE_URL)) {
                Storage::disk('public')->delete($previous);
            }

            $updateData['image_url'] = $this->resolveImageValue($data, $previous);
        }

        $product->update($updateData);

        return $product->fresh()
            ->load('category')
            ->loadSum('inventories as total_stock', 'quantity');
    }

    public function delete(User $user, Product $product): void
    {
        $this->authorizeStoreAccess($user, $product->store_id);

        if ($product->billingItems()->exists()) {
            abort(response()->json([
                'message' => 'Cannot delete product because it already appears in billing items.',
            ], 422));
        }

        if ($product->inventories()->where('quantity', '>', 0)->exists()) {
            abort(response()->json([
                'message' => 'Cannot delete product because inventory still exists.',
            ], 422));
        }

        $rawImage = $product->getRawOriginal('image_url');

        if ($rawImage && !filter_var($rawImage, FILTER_VALIDATE_URL)) {
            Storage::disk('public')->delete($rawImage);
        }

        $product->delete();
    }

    private function shouldUpdateImage(array $data): bool
    {
        if (!empty($data['clear_image'])) {
            return true;
        }

        if (isset($data['image']) && $data['image'] instanceof UploadedFile) {
            return true;
        }

        if (array_key_exists('image_url', $data) && filled($data['image_url'])) {
            return true;
        }

        return false;
    }

    private function resolveImageValue(array $data, ?string $previous): ?string
    {
        if (!empty($data['clear_image'])) {
            return null;
        }

        if (isset($data['image']) && $data['image'] instanceof UploadedFile) {
            return $data['image']->store('products', 'public');
        }

        if (array_key_exists('image_url', $data) && filled($data['image_url'])) {
            return $data['image_url'];
        }

        return $previous;
    }
}