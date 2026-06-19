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
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    use AuthorizesPermission;

    public function __construct(
        private readonly ProductService $service
    ) {}

public function index(Request $request): JsonResponse
{
    $perPage = max(1, min((int) ($request->per_page ?? 15), 100));
    $user    = $request->user();

    $query = Product::query()
        ->select([
            'product_id',
            'product_name',
            'price',
            'vat_rate',
            'sku',
            'category_id',
            'store_id',
            'is_active',
            'image_url',
        ])
        ->with([
            'category:category_id,category_name',
            'store:store_id,store_name',
        ])
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
            $q->where(function ($sub) use ($search) {
                $sub->where('product_name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        })
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
        ->when(
            !$request->filled('category_ids') && $request->filled('category_id'),
            fn($q) => $q->where('category_id', (int) $request->category_id)
        )
        ->when(
            $request->has('is_active') && $request->is_active !== '',
            fn($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN))
        )
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
        ],
    ]);
}
    public function store(StoreProductRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('products.manage')) return $error;

        return response()->json([
            'message' => 'Product created successfully.',
            'data'    => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        return response()->json([
            'message' => 'Product retrieved successfully.',
            'data'    => $this->service->show($request->user(), $product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        if ($error = $this->authorizePermission('products.manage')) return $error;

        return response()->json([
            'message' => 'Product updated successfully.',
            'data'    => $this->service->update($request->user(), $product, $request->validated()),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        if ($error = $this->authorizePermission('products.manage')) return $error;

        $this->service->delete($request->user(), $product);

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }
}