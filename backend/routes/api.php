<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingItemController;
use App\Http\Controllers\Api\GrnController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AccessControlController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PublicDocumentController;
use App\Http\Controllers\Api\PosDashboardController;
use App\Http\Controllers\Api\RewardRuleController;
use App\Http\Controllers\Api\SuperAdminDashboardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ManagerDashboardController;
use App\Http\Controllers\Api\PosSessionController;
use App\Http\Controllers\Api\CashierShiftController;
use App\Http\Controllers\Api\SupplierController;



Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset');
});
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logoutAll');
        Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->name('auth.verify');
        Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->name('auth.resend');

        Route::get('/sessions', [AuthController::class, 'sessions'])->name('auth.sessions.index');
        Route::delete('/sessions', [AuthController::class, 'revokeAllSessions'])->name('auth.sessions.destroyAll');
        Route::delete('/sessions/{sessionId}', [AuthController::class, 'revokeSession'])->name('auth.sessions.destroy');

    });
     Route::get('/cashier-shifts/today', [CashierShiftController::class, 'today']);
    Route::post('/cashier-shifts/open', [CashierShiftController::class, 'open']);
    Route::post('/cashier-shifts/close', [CashierShiftController::class, 'close']);
    Route::get('/cashier-shifts/daily-sales', [CashierShiftController::class, 'dailySales']);

    // Manager / Admin endpoints
    Route::get('/cashier-shifts/all-cashiers', [CashierShiftController::class, 'allCashiers']);
    Route::get('/cashier-shifts/report', [CashierShiftController::class, 'report']);

    Route::get('/grns', [GrnController::class, 'index']);
    Route::post('/grns', [GrnController::class, 'store']);
    Route::get('/grns/{grn}', [GrnController::class, 'show']);
    Route::put('/grns/{grn}', [GrnController::class, 'update']);
    Route::delete('/grns/{grn}', [GrnController::class, 'destroy']);

    Route::post('/grns/{grn}/items', [GrnController::class, 'addItem']);
    Route::put('/grns/{grn}/items/{grnItem}', [GrnController::class, 'updateItem']);
    Route::delete('/grns/{grn}/items/{grnItem}', [GrnController::class, 'deleteItem']);
    Route::post('/grns/{grn}/complete', [GrnController::class, 'complete']);
    Route::post('/grns/{grn}/charge', [GrnController::class, 'charge']);

    

Route::prefix('access-control')->group(function () {
    Route::get('/', [AccessControlController::class, 'index']);
    Route::put('/roles/{roleName}/permissions', [AccessControlController::class, 'updateRolePermissions']);
    Route::put('/users/{user}/role',            [AccessControlController::class, 'assignUserRole']);
    Route::get('/users/{user}/permissions',     [AccessControlController::class, 'getUserPermissions']);
    Route::put('/users/{user}/permissions',     [AccessControlController::class, 'updateUserPermissions']);
});

        Route::get('/pos-session', [PosSessionController::class, 'show']);
        Route::put('/pos-session', [PosSessionController::class, 'upsert']);
        Route::delete('/pos-session', [PosSessionController::class, 'destroy']);

    Route::apiResource('stores', StoreController::class);
    Route::get('stores/{store}/settings', [StoreController::class, 'settings'])->name('stores.settings');
    Route::put('stores/{store}/settings', [StoreController::class, 'updateSettings'])->name('stores.settings.update');

    Route::apiResource('users', UserController::class);
    Route::post('users/{user}/stores', [UserController::class, 'syncStores'])->name('users.stores.sync');

    Route::get('/pos/bootstrap', [PosDashboardController::class, 'bootstrap']);

    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('suppliers', SupplierController::class);


    Route::get('inventory/history', [InventoryController::class, 'history'])->name('inventory.history');
    Route::post('inventory/consume-fifo', [InventoryController::class, 'consumeFifo'])->name('inventory.consume-fifo');
    Route::apiResource('inventory', InventoryController::class)->parameters([
        'inventory' => 'inventoryItem',
    ]);
    Route::patch('inventory/{inventoryItem}/adjust', [InventoryController::class, 'adjust']);

    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('billings', BillingController::class);
    Route::post('billings/{id}/restore', [BillingController::class, 'restore'])->name('billings.restore');

    Route::apiResource('billing-items', BillingItemController::class);
    Route::post('billing-items/{id}/restore', [BillingItemController::class, 'restore'])->name('billing-items.restore');

    Route::get('billings/{billing}/items', [BillingItemController::class, 'index'])->name('billings.items.index');
    Route::post('billings/{billing}/items', [BillingItemController::class, 'store'])->name('billings.items.store');

    Route::post('billings/charge', [PaymentController::class, 'chargeCart'])->name('billings.charge.cart');
    Route::post('billings/{billing}/charge', [PaymentController::class, 'charge'])->name('billings.charge');

        Route::prefix('reward-rules')->group(function () {
        Route::get('/',                 [RewardRuleController::class, 'index']);
        Route::post('/',                [RewardRuleController::class, 'store']);
        Route::put('/{rewardRule}',     [RewardRuleController::class, 'update']);
        Route::delete('/{rewardRule}',  [RewardRuleController::class, 'destroy']);
        Route::get('/customer-loyalty', [RewardRuleController::class, 'customerLoyalty']);
        Route::post('/claim-chapa5', [RewardRuleController::class, 'claimChapa5']);
    }); 
Route::prefix('dashboard/super-admin')->group(function () {
    Route::get('/',              [DashboardController::class, 'superAdmin'])->name('dashboard.super-admin');
    Route::get('/trends',        [DashboardController::class, 'trends'])->name('dashboard.super-admin.trends');
    Route::get('/operations',    [DashboardController::class, 'operations'])->name('dashboard.super-admin.operations');
    Route::get('/subscriptions', [DashboardController::class, 'subscriptions'])->name('dashboard.super-admin.subscriptions');
    Route::get('/security',      [DashboardController::class, 'security'])->name('dashboard.super-admin.security');
});
Route::prefix('dashboard/manager')->group(function () {
    Route::get('/',         [ManagerDashboardController::class, 'summary'])->name('dashboard.manager.summary');
    Route::get('/trends',   [ManagerDashboardController::class, 'trends'])->name('dashboard.manager.trends');
    Route::get('/activity', [ManagerDashboardController::class, 'activity'])->name('dashboard.manager.activity');
    Route::post('/finalize-shift', [ManagerDashboardController::class, 'finalizeShift'])->name('dashboard.manager.finalize-shift');
});


        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
      Route::get('payments/{payment:payment_id}', [PaymentController::class, 'show']);
});

Route::get('public/documents/{mode}/{uuid}', [PublicDocumentController::class, 'show'])
    ->where('mode', 'receipt|invoice')
    ->name('public.documents.show');

Route::get('public/documents/{mode}/{uuid}/download', [PublicDocumentController::class, 'download'])
    ->where('mode', 'receipt|invoice')
    ->name('public.documents.download');