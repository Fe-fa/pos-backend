<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingItemController;
use App\Http\Controllers\Api\GrnController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentVoucherController;
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
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\MpesaController;
use App\Http\Controllers\Api\MpesaRealtimePaymentController;
use App\Http\Controllers\Api\MpesaCallbackController;
use App\Http\Controllers\Api\TransactionDeskController;
use App\Http\Controllers\Api\ChequeReconciliationWebhookController;



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

    Route::prefix('mpesa')->group(function () {
    Route::post('/register-c2b-urls', [MpesaRealtimePaymentController::class, 'registerUrls']);
    Route::prefix('/realtime')->group(function () {
        Route::post('/attempts', [MpesaRealtimePaymentController::class, 'startWaitingAttempt']);
        Route::post('/attempts/{attempt}/cancel', [MpesaRealtimePaymentController::class, 'cancelWaitingAttempt']);
        Route::post('/transactions/{transaction}/claim', [MpesaRealtimePaymentController::class, 'claimPayment']);
        Route::get('/unassigned', [MpesaRealtimePaymentController::class, 'unassignedIndex']);
        Route::post('/unassigned/{unassigned}/apply', [MpesaRealtimePaymentController::class, 'applyUnassigned']);
    });
    Route::post('/stk-push',                     [MpesaController::class, 'initiateStkPush']);
    Route::get ('/status/{checkoutRequestId}',   [MpesaController::class, 'status']);
    Route::post('/cancel/{checkoutRequestId}',   [MpesaController::class, 'cancel']);
    Route::post('/validate-receipt',             [MpesaController::class, 'validateReceipt']);
    Route::get('/manual-status/{trackingReference}', [MpesaController::class, 'manualStatus']);
    Route::post('/pull-match',                   [MpesaController::class, 'pullMatch']);
    Route::get('/account-balance',               [MpesaController::class, 'latestAccountBalance']);
    Route::post('/account-balance/request',      [MpesaController::class, 'requestAccountBalance']);

    
  });
      Route::post('/mpesa/b2b/supplier-payment', [MpesaController::class, 'initiateB2bSupplierPayment']);
    Route::get('/mpesa/b2b/status/{trackingReference}', [MpesaController::class, 'b2bStatus']);

    Route::get('payments/reversal-queue', [PaymentController::class, 'reversalQueue']);
Route::post('payments/{payment:payment_id}/mpesa-reversal/request', [PaymentController::class, 'requestMpesaReversal']);
Route::post('payments/{payment:payment_id}/mpesa-reversal/approve', [PaymentController::class, 'approveMpesaReversal']);
Route::post('payments/{payment:payment_id}/mpesa-reversal/reject', [PaymentController::class, 'rejectMpesaReversal']);

    Route::get('/transaction-desk', [TransactionDeskController::class, 'index']);
    Route::post('/transaction-desk/expenses', [TransactionDeskController::class, 'storeExpense']);
    Route::post('/cashier-shifts/cash-drop', [CashierShiftController::class, 'cashDrop']);

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
    Route::get('suppliers/{supplier}/statement', [SupplierController::class, 'statement']);

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
    Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
    Route::post('purchase-orders/{purchaseOrder}/place', [PurchaseOrderController::class, 'place']);

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
    Route::post('billings/{billing}/dispatch-documents', [BillingController::class, 'dispatchDocuments'])->name('billings.dispatch-documents');

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
        Route::get('payments/cheque-banks', [PaymentController::class, 'chequeBanks']);
        Route::post('payments/{paymentRef}/cheque/authorize', [PaymentController::class, 'authorizeCheque']);
        Route::post('payments/{paymentRef}/cheque/verify', [PaymentController::class, 'verifyCheque']);
        Route::post('payments/{paymentRef}/cheque/submit', [PaymentController::class, 'submitCheque']);
        Route::post('payments/{paymentRef}/cheque/deposit', [PaymentController::class, 'depositCheque']);
        Route::post('payments/{paymentRef}/cheque/clear', [PaymentController::class, 'clearCheque']);
        Route::post('payments/{paymentRef}/cheque/return', [PaymentController::class, 'returnCheque']);
        Route::get('payments/{payment:payment_id}', [PaymentController::class, 'show']);

        Route::get('payment-vouchers', [PaymentVoucherController::class, 'index'])->name('payment-vouchers.index');
        Route::post('payment-vouchers', [PaymentVoucherController::class, 'store'])->name('payment-vouchers.store');
        Route::get('payment-vouchers/{paymentVoucher:payment_voucher_id}', [PaymentVoucherController::class, 'show'])->name('payment-vouchers.show');
        Route::put('payment-vouchers/{paymentVoucher:payment_voucher_id}', [PaymentVoucherController::class, 'update'])->name('payment-vouchers.update');
        Route::post('payment-vouchers/{paymentVoucher}/generate-receipt', [PaymentVoucherController::class, 'generateReceipt']);

});

Route::get('public/documents/{mode}/{uuid}', [PublicDocumentController::class, 'show'])
    ->where('mode', 'receipt|invoice')
    ->name('public.documents.show');

Route::get('public/documents/{mode}/{uuid}/download', [PublicDocumentController::class, 'download'])
    ->where('mode', 'receipt|invoice')
    ->name('public.documents.download');
Route::prefix('mpesa/callbacks')
    // ->middleware('mpesa.callback')
    ->group(function () {
        Route::post('/stk',                    [MpesaCallbackController::class, 'stk']);
        Route::post('/c2b/validation',         [MpesaCallbackController::class, 'c2bValidation']);
        Route::post('/c2b/confirmation',       [MpesaCallbackController::class, 'c2bConfirmation']);
        Route::post('/tx-status/result',       [MpesaCallbackController::class, 'txStatusResult']);
        Route::post('/tx-status/timeout',      [MpesaCallbackController::class, 'txStatusTimeout']);
        Route::post('/reversal/result',  [MpesaCallbackController::class, 'reversalResult']);
Route::post('/reversal/timeout', [MpesaCallbackController::class, 'reversalTimeout']);
    });

Route::prefix('webhooks/mpesa/b2b')
    ->middleware('mpesa.callback')
    ->group(function () {
        Route::post('/result',  [MpesaCallbackController::class, 'b2bResult']);
        Route::post('/timeout', [MpesaCallbackController::class, 'b2bTimeout']);
    });

Route::prefix('webhooks/mpesa/balance')
    ->middleware('mpesa.callback')
    ->group(function () {
        Route::post('/result',  [MpesaCallbackController::class, 'balanceResult']);
        Route::post('/timeout', [MpesaCallbackController::class, 'balanceTimeout']);
    });
Route::post('webhooks/cheques/reconciliation', [ChequeReconciliationWebhookController::class, 'handle']);
