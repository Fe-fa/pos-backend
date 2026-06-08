<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) ($request->per_page ?? 12), 100));

        $user = $request->user();

        $query = Product::query()
            ->with(['category', 'store'])
            ->withCount('inventories')
            ->withSum('inventories as total_stock', 'quantity');

        if (!$user->isAdmin()) {
            $query->whereIn('store_id', $this->service->allowedStoreIds($user));
        }

        $query
            ->when($request->store_id, function ($q, $storeId) use ($user) {
                $this->service->authorizeStoreAccess($user, $storeId);
                $q->where('store_id', $storeId);
            })
            ->when($request->search, function ($q, $search) {
                $search = str_replace(['%', '_'], ['\%', '\_'], trim($search));

                $q->where(function ($subQuery) use ($search) {
                    $subQuery->where('product_name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when(
                $request->filled('category_ids'),
                function ($q) use ($request) {
                    $rawCategoryIds = $request->input('category_ids');

                    $categoryIds = is_array($rawCategoryIds)
                        ? $rawCategoryIds
                        : explode(',', (string) $rawCategoryIds);

                    $categoryIds = collect($categoryIds)
                        ->map(fn ($id) => (int) trim($id))
                        ->filter(fn ($id) => $id > 0)
                        ->values()
                        ->all();

                    if (!empty($categoryIds)) {
                        $q->whereIn('category_id', $categoryIds);
                    }
                },
                function ($q) use ($request) {
                    if ($request->filled('category_id')) {
                        $q->where('category_id', (int) $request->category_id);
                    }
                }
            )
            ->when($request->has('is_active') && $request->is_active !== '', function ($q) use ($request) {
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderByDesc('product_id');

        $products = $query->paginate($perPage);
        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
                'from'         => $products->firstItem(),
                'to'           => $products->lastItem(),
                'path'         => $products->path(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Product created successfully.',
            'data' => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        return response()->json([
            'message' => 'Product retrieved successfully.',
            'data' => $this->service->show($request->user(), $product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => $this->service->update($request->user(), $product, $request->validated()),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->service->delete($request->user(), $product);

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }
}
