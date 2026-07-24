<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use AuthorizesPermission;

    public function __construct(
        private readonly CategoryService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('categories.view')) return $error;

        $perPage = max(1, min((int) ($request->per_page ?? 5), 100));
        $user    = $request->user();

        if ($request->filled('store_id')) {
            $this->service->authorizeStoreAccess($user, (int) $request->store_id);
        }

        $query = Category::query()
            ->select([
                'category_id',
                'store_id',
                'category_name',
                'description',
            ])
            ->withCount('products');

        if (!$user->isAdmin()) {
            $query->whereIn('categories.store_id', $this->service->allowedStoreIds($user));
        }

        $query
            ->when($request->filled('store_id'), fn($q) =>
                $q->where('categories.store_id', (int) $request->store_id)
            )
            ->when($request->filled('search'), fn($q) =>
                $q->where('categories.category_name', 'like', '%' . trim($request->search) . '%')
            )
            ->orderBy('categories.category_name');

        $categories = $query->paginate($perPage);

        return response()->json([
            'data' => $categories->items(),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'last_page'    => $categories->lastPage(),
                'per_page'     => $categories->perPage(),
                'total'        => $categories->total(),
                'from'         => $categories->firstItem(),
                'to'           => $categories->lastItem(),
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('categories.manage')) return $error;

        return response()->json([
            'message' => 'Category created successfully.',
            'data'    => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        if ($error = $this->authorizePermission('categories.view')) return $error;

        $this->service->authorizeStoreAccess($request->user(), $category->store_id);

        return response()->json([
            'message' => 'Category retrieved successfully.',
            'data'    => $this->service->show($category, $request->user()),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        if ($error = $this->authorizePermission('categories.manage')) return $error;

        $this->service->authorizeStoreAccess($request->user(), $category->store_id);

        return response()->json([
            'message' => 'Category updated successfully.',
            'data'    => $this->service->update($request->user(), $category, $request->validated()),
        ]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        if ($error = $this->authorizePermission('categories.manage')) return $error;

        $this->service->authorizeStoreAccess($request->user(), $category->store_id);

        $this->service->delete($request->user(), $category);

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}
