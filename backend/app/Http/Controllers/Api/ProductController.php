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
        if ($error = $this->authorizePermission('products.view')) return $error;

        $perPage = max(1, min((int) ($request->get('per_page', 16)), 100));
        $user    = $request->user();

        $query = Product::query()
            ->select([
                'product_id',
                'product_name',
                'description',
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
                'category:category_id,category_name',
                'store:store_id,store_name',
            ])
            ->withCount('inventories')
            ->withSum('inventories as total_stock', 'quantity');

        if (!$user->isAdmin()) {
            $query->whereIn('store_id', $this->service->allowedStoreIds($user));
        }

        $query
            ->when($request->filled('store_id'), function ($q) use ($user, $request) {
                $this->service->authorizeStoreAccess($user, $request->store_id);
                $q->where('store_id', $request->store_id);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
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
            ->when(
                $request->filled('tax_class'),
                function ($q) use ($request) {
                    $taxClass = $request->tax_class;
                    if ($taxClass === 'vat') {
                        $q->where('vat_rate', '>', 0);
                    } elseif ($taxClass === 'no_vat') {
                        $q->where(function ($sub) {
                            $sub->whereNull('vat_rate')->orWhere('vat_rate', 0);
                        });
                    }
                }
            )
            ->orderByDesc('product_id');

        $products = $query->paginate($perPage);

        // Compute lightweight stats for the summary cards (same store scope)
        $statsQuery = Product::query();
        if (!$user->isAdmin()) {
            $statsQuery->whereIn('store_id', $this->service->allowedStoreIds($user));
        }
        if ($request->filled('store_id')) {
            $statsQuery->where('store_id', $request->store_id);
        }

        $stats = [
            'total_active_skus'  => (clone $statsQuery)->where('is_active', true)->count(),
            'total_catalog_value' => (clone $statsQuery)->where('is_active', true)->sum('price'),
            'missing_image_count' => (clone $statsQuery)
                ->where(function ($q) {
                    $q->whereNull('image_url')->orWhere('image_url', '');
                })->count(),
            'missing_barcode_count' => (clone $statsQuery)
                ->where(function ($q) {
                    $q->whereNull('sku')->orWhere('sku', '');
                })->count(),
        ];

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
                'from'         => $products->firstItem(),
                'to'           => $products->lastItem(),
                'has_more'     => $products->hasMorePages(),
                'has_prev_page' => $products->currentPage() > 1,
                'has_next_page' => $products->hasMorePages(),
            ],
            'stats' => $stats,
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
        if ($error = $this->authorizePermission('products.view')) return $error;

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

    try {
        $this->service->delete($request->user(), $product);

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    } catch (\Illuminate\Database\QueryException $e) {
        if ($e->getCode() === '23000') {
            return response()->json([
                'message' => 'This product cannot be deleted because it has existing billing records. Consider deactivating it instead.',
            ], 409);
        }

        return response()->json([
            'message' => 'Something went wrong while deleting the product.',
        ], 500);
    }
}
}
