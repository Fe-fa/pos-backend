<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use AuthorizesPermission;

    public function __construct(
        private readonly ProductService $service
    ) {}

    /**
     * Display a listing of the optimized products payload.
     */
 public function index(Request $request): JsonResponse
{
    // Restrict perPage boundaries safely
    $perPage = max(1, min((int) ($request->get('per_page', 14)), 100));
    $user    = $request->user();

    $query = Product::query()
        ->select([
            'product_id',
            'product_name',
            'price',
            'cost_price',
            'vat_rate',
            'sku',
            'category_id',
            'store_id',
            'is_active',
            'image_url',
        ])
        ->with([
            // Select ONLY required columns to optimize database memory memory usage
            'category:category_id,category_name',
            'store:store_id,store_name',
        ])
        ->withCount('inventories')
        ->withSum('inventories as total_stock', 'quantity');

    // 1. Enforce Multi-Tenancy Scope / Global Store Restrictions
    if (!$user->isAdmin()) {
        $query->whereIn('store_id', $this->service->allowedStoreIds($user));
    }

    // 2. Apply Dynamic Request Filters
    $query
        // Always enforce the selected Store ID from UI if passed
        ->when($request->filled('store_id'), function ($q) use ($user, $request) {
            $this->service->authorizeStoreAccess($user, $request->store_id);
            $q->where('store_id', $request->store_id);
        })
        // Global Search (Name & SKU)
        ->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;
            $q->where(function ($sub) use ($search) {
                $sub->where('product_name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        })
        // Array or Comma-separated Category filter
        ->when($request->filled('category_ids'), function ($q) use ($request) {
            $ids = collect(
                is_array($request->category_ids)
                    ? $request->category_ids
                    : explode(',', (string) $request->category_ids)
            )
                ->map(fn($id) => (int) trim($id))
                ->filter()
                ->values()
                ->all();

            if (!empty($ids)) {
                $q->whereIn('category_id', $ids);
            }
        })
        // Single Category ID backup filter
        ->when(
            !$request->filled('category_ids') && $request->filled('category_id'),
            fn($q) => $q->where('category_id', (int) $request->category_id)
        )
        // Status filtering (Active/Inactive)
        ->when(
            $request->has('is_active') && $request->is_active !== '',
            fn($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN))
        )
        // Order by newest created or high ID
        ->orderByDesc('product_id');

    // Execute pagination
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
            'has_more'     => $products->hasMorePages(), // Helpful addition for frontend buttons
        ],
    ]);
}

    /**
     * Store a newly created product in storage.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('products.manage')) return $error;

        return response()->json([
            'message' => 'Product created successfully.',
            'data'    => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

    /**
     * Display the specified product.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        return response()->json([
            'message' => 'Product retrieved successfully.',
            'data'    => $this->service->show($request->user(), $product),
        ]);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        if ($error = $this->authorizePermission('products.manage')) return $error;

        return response()->json([
            'message' => 'Product updated successfully.',
            'data'    => $this->service->update($request->user(), $product, $request->validated()),
        ]);
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        if ($error = $this->authorizePermission('products.manage')) return $error;

        $this->service->delete($request->user(), $product);

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }
}