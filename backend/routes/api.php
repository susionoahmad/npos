<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'register']);
Route::post('/payments/midtrans/callback', [\App\Http\Controllers\Api\SubscriptionController::class, 'callback']);

Route::middleware(['auth:sanctum', 'active.user', 'active.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/setup-wizard', [AuthController::class, 'setupWizard']);
    Route::get('/me', function () {
        $user = auth()->user();
        if ($user && $user->tenant) {
            $user->tenant->checkAndSyncExpiredStatus();
        }
        return $user->load(['store', 'tenant']);
    });

    // Tenant / Multi-store switching for owners
    Route::get('/tenant/stores', [\App\Http\Controllers\Api\TenantController::class, 'getStores']);
    Route::post('/tenant/switch-store', [\App\Http\Controllers\Api\TenantController::class, 'switchStore']);

    // Cashier Sessions (Read & Close session can still happen if expired)
    Route::get('/cashier-sessions', [\App\Http\Controllers\Api\CashierSessionController::class, 'index']);
    Route::get('/cashier-sessions/active', [\App\Http\Controllers\Api\CashierSessionController::class, 'getActive']);
    Route::get('/cashier-sessions/active/summary', [\App\Http\Controllers\Api\CashierSessionController::class, 'getSummary']);
    Route::post('/cashier-sessions/close', [\App\Http\Controllers\Api\CashierSessionController::class, 'close']);

    // Cashier Cash Mutations read-only
    Route::get('/cashier-mutations', [\App\Http\Controllers\Api\CashierCashMutationController::class, 'index']);

    // Kas Besar - balance
    Route::get('/kas-besar/balance', [\App\Http\Controllers\Api\GeneralCashController::class, 'balance']);

    // Read-only routes for Cashiers & all authenticated users
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
    Route::get('/reports/daily-summary', [ReportController::class, 'dailySummary']);

    // Operations requiring active subscription and store license (Open Shift, Cash Mutation, POS Transactions)
    Route::middleware('active.subscription')->group(function () {
        Route::post('/cashier-sessions/open', [\App\Http\Controllers\Api\CashierSessionController::class, 'open']);
        Route::post('/cashier-mutations', [\App\Http\Controllers\Api\CashierCashMutationController::class, 'store']);
        Route::post('/transactions', [TransactionController::class, 'store']);
    });

    // Write access & management for admin, owner, and superadmin
    Route::middleware('role:admin,owner,superadmin')->group(function () {
        // Kas Besar index
        Route::get('/kas-besar', [\App\Http\Controllers\Api\GeneralCashController::class, 'index']);

        // Laporan Keuangan (admin, owner, superadmin only)
        Route::get('/reports/sales-by-cashier', [ReportController::class, 'salesByCashier']);
        Route::get('/reports/payment-recap',    [ReportController::class, 'paymentRecap']);
        Route::get('/reports/top-products',     [ReportController::class, 'topProducts']);
        Route::get('/reports/slow-products',    [ReportController::class, 'slowProducts']);
        Route::get('/reports/stock-current',    [ReportController::class, 'stockCurrent']);
        Route::get('/reports/stock-mutations',  [ReportController::class, 'stockMutations']);
        Route::get('/reports/cash-flow',        [ReportController::class, 'cashFlow']);
        Route::get('/reports/profit-loss',      [ReportController::class, 'profitLoss']);
        Route::get('/reports/purchase-summary', [ReportController::class, 'purchaseSummary']);

        Route::get('/products-expiry', [ProductController::class, 'expiryWarnings']);
        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::get('/store', [StoreController::class, 'show']);
        Route::put('/settings/store', [SettingsController::class, 'updateStore']);

        // Operations that modify data and require active subscription
        Route::middleware('active.subscription')->group(function () {
            // Kas Besar store
            Route::post('/kas-besar', [\App\Http\Controllers\Api\GeneralCashController::class, 'store']);

            // Products
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{product}', [ProductController::class, 'update']);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);
            Route::post('/products/import-csv', [ProductController::class, 'importCsv']);
            Route::post('/products/upload-image', [ProductController::class, 'uploadImage']);

            // Categories
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

            // Suppliers
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);

            // Users
            Route::post('/users', [UserController::class, 'store']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);

            // Purchases
            Route::post('/purchases', [PurchaseController::class, 'store']);
            Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy']);
            Route::post('/purchases/{purchase}/pay', [PurchaseController::class, 'payDebt']);

            // Store license activation
            Route::post('/store/activate-license', [StoreController::class, 'activateLicense']);
        });

                // Superadmin-only routes
        Route::middleware('role:superadmin')->group(function () {
            Route::get('/superadmin/tenants', [\App\Http\Controllers\Api\SuperadminController::class, 'getTenants']);
            Route::post('/superadmin/tenants', [\App\Http\Controllers\Api\SuperadminController::class, 'createTenant']);
            Route::post('/superadmin/stores/{store}/toggle-license', [\App\Http\Controllers\Api\SuperadminController::class, 'toggleLicense']);
            Route::get('/superadmin/settings', [\App\Http\Controllers\Api\SuperadminController::class, 'getSettings']);
            Route::post('/superadmin/settings', [\App\Http\Controllers\Api\SuperadminController::class, 'updateSettings']);
            Route::post('/superadmin/tenants/{tenant}/subscription', [\App\Http\Controllers\Api\SuperadminController::class, 'updateTenantSubscription']);

            // Subscription Plans management
            Route::get('/superadmin/plans', [\App\Http\Controllers\Api\SuperadminController::class, 'getPlans']);
            Route::post('/superadmin/plans', [\App\Http\Controllers\Api\SuperadminController::class, 'storePlan']);
            Route::put('/superadmin/plans/{plan}', [\App\Http\Controllers\Api\SuperadminController::class, 'updatePlan']);
            Route::delete('/superadmin/plans/{plan}', [\App\Http\Controllers\Api\SuperadminController::class, 'deletePlan']);
        });

        // Owner-only routes (Billing & renewal)
        Route::middleware('role:owner')->group(function () {
            Route::put('/tenant', [\App\Http\Controllers\Api\TenantController::class, 'updateTenant']);
            Route::post('/tenant/stores', [\App\Http\Controllers\Api\TenantController::class, 'createStore']);
            Route::get('/billing/overview', [\App\Http\Controllers\Api\SubscriptionController::class, 'getBillingOverview']);
            Route::post('/billing/checkout', [\App\Http\Controllers\Api\SubscriptionController::class, 'checkout']);
        });
    });
});