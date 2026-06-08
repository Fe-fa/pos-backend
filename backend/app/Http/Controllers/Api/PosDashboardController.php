<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Billing;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PosDashboardController extends Controller
{
    public function bootstrap(Request $request)
    {
        $user = $request->user();
        $storeId = $request->header('X-Store-Id') ?? $request->input('store_id', 1); 

        // 1. Fetch Categories via CategoryController@index
        // This honors whatever page size or pagination logic you have set there
        $categoryControllerResponse = app(CategoryController::class)->index($request);
        $categoriesPayload = $categoryControllerResponse->getData();
        
        // Handle both paginated resources (with data wrapper) and regular collections
        $categories = isset($categoriesPayload->data) ? $categoriesPayload->data : $categoriesPayload;

        // 2. Fetch Products via ProductController@index
        // This honors your customized $pageSize settings, search params, etc.
        $productControllerResponse = app(ProductController::class)->index($request);
        $productsPayload = $productControllerResponse->getData();
        
        $products = isset($productsPayload->data) ? $productsPayload->data : $productsPayload;

        // 3. Safe Customer Fetch (keeps your original baseline queries)
        $customers = Customer::where('store_id', $storeId)
            ->orderBy('full_name', 'asc')
            ->limit(30)
            ->get();

        // 4. Safe Draft Billing Fetch
        $draftBillings = Billing::where('store_id', $storeId)
            ->where('is_draft', true)
            ->where('user_id', $user->user_id)
            ->orderBy('billing_id', 'desc')
            ->limit(30)
            ->get();

        // 5. Safe Today Sales Calculation
        $today = Carbon::today()->toDateString();
        $todaySalesSum = DB::table('payments')
            ->join('billing', 'billing.billing_id', '=', 'payments.billing_id')
            ->where('billing.user_id', $user->user_id)
            ->whereNull('billing.deleted_at')
            ->whereDate('payments.payment_date', $today)
            ->sum('payments.amount_received');

    
        return response()->json([
            'success' => true,
            'data' => [
                'categories'      => $categories,
                'customers'       => $customers,
                'draftBillings'   => $draftBillings,
                'products'        => $products,
                'todaySalesSum'   => (float) $todaySalesSum,
            ]
        ]);
    }
}