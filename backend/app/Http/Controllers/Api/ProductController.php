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
    $perPage = (int) ($request->per_page ?? 12);
    $user = $request->user();

    $query = Product::query()
        ->with(['category'])
        ->withCount('inventories')
        ->withSum('inventories as total_stock', 'quantity');

    if (!$user->isAdmin()) {
        $query->whereIn('store_id', $this->service->allowedStoreIds($user));
    }
    // 3. Conditional Filtering matching your exact format syntax
    $query->when($request->store_id, function ($q, $storeId) use ($user) {
            $this->service->authorizeStoreAccess($user, $storeId);
            $q->where('store_id', $storeId);
        })
        ->when($request->search, function ($q, $search) {
            $search = trim($search);
            $q->where(function ($subQuery) use ($search) {
                $subQuery->where('product_name', 'like', "%{$search}%")
                         ->orWhere('sku', 'like', "%{$search}%");
            });
        })
        ->when($request->category_id, function ($q, $categoryId) {
            $q->where('category_id', (int) $categoryId);
        })
        ->when($request->has('is_active') && $request->is_active !== '', function ($q) use ($request) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        })
        ->orderByDesc('product_id'); // Match your original latest sorting

    
    $products = $query->paginate($perPage);
    return response()->json([
        'data' => $products->items(),
        'meta' => [
            'current_page' => $products->currentPage(),
            'last_page'    => $products->lastPage(),
            'per_page'     => $products->perPage(),
            'total'        => $products->total(),
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