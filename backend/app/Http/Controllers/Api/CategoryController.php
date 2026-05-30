<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Categories retrieved successfully.',
            'data' => $this->service->paginate(
                $request->user(),
                $request->only('store_id', 'search', 'per_page')
            ),
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Category created successfully.',
            'data' => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        return response()->json([
            'message' => 'Category retrieved successfully.',
            'data' => $this->service->show($category, $request->user()),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        return response()->json([
            'message' => 'Category updated successfully.',
            'data' => $this->service->update($request->user(), $category, $request->validated()),
        ]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->service->delete($request->user(), $category);

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}
