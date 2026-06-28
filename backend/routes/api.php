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
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'register']);

Route::middleware(['auth:sanctum', 'active.user', 'active.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/setup-wizard', [AuthController::class, 'setupWizard']);
    Route::get('/me', fn () => auth()->user()->load(['store', 'tenant']));

    // Tenant / Multi-store switching for owners
    Route::get('/tenant/stores', [\App\Http\Controllers\Api\TenantController::class, 'getStores']);
    Route::post('/tenant/switch-store', [\App\Http\Controllers\Api\TenantController::class, 'switchStore']);

    // Cashier Session shift management
    Route::get('/cashier-sessions', [\App\Http\Controllers\Api\CashierSessionController::class, 'index']);
    Route::get('/cashier-sessions/active', [\App\Http\Controllers\Api\CashierSessionController::class, 'getActive']);
    Route::get('/cashier-sessions/active/summary', [\App\Http\Controllers\Api\CashierSessionController::class, 'getSummary']);
    Route::post('/cashier-sessions/open', [\App\Http\Controllers\Api\CashierSessionController::class, 'open']);
    Route::post('/cashier-sessions/close', [\App\Http\Controllers\Api\CashierSessionController::class, 'close']);

    // Cashier Cash Mutations
    Route::get('/cashier-mutations', [\App\Http\Controllers\Api\CashierCashMutationController::class, 'index']);
    Route::post('/cashier-mutations', [\App\Http\Controllers\Api\CashierCashMutationController::class, 'store']);

    // Kas Besar - balance accessible to all authenticated users
    Route::get('/kas-besar/balance', [\App\Http\Controllers\Api\GeneralCashController::class, 'balance']);

    // Read-only routes for Cashiers & all authenticated users
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);

    Route::apiResource('transactions', TransactionController::class)->only(['index', 'store', 'show']);
    Route::get('/reports/daily-summary', [ReportController::class, 'dailySummary']);

    // Write access & management for admin, owner, and superadmin
    Route::middleware('role:admin,owner,superadmin')->group(function () {
        // Kas Besar
        Route::get('/kas-besar', [\App\Http\Controllers\Api\GeneralCashController::class, 'index']);
        Route::post('/kas-besar', [\App\Http\Controllers\Api\GeneralCashController::class, 'store']);

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

        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/import-csv', [ProductController::class, 'importCsv']);
        Route::get('/products-expiry', [ProductController::class, 'expiryWarnings']);
        Route::post('/products/upload-image', [ProductController::class, 'uploadImage']);

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);

        Route::apiResource('users', UserController::class);
        Route::apiResource('purchases', PurchaseController::class)->only(['index', 'store', 'show', 'destroy']);
        Route::post('/purchases/{purchase}/pay', [PurchaseController::class, 'payDebt']);
        Route::get('/store', [StoreController::class, 'show']);
        Route::post('/store/activate-license', [StoreController::class, 'activateLicense']);
        Route::put('/settings/store', [SettingsController::class, 'updateStore']);

        // Superadmin-only routes
        Route::middleware('role:superadmin')->group(function () {
            Route::get('/superadmin/tenants', [\App\Http\Controllers\Api\SuperadminController::class, 'getTenants']);
            Route::post('/superadmin/tenants', [\App\Http\Controllers\Api\SuperadminController::class, 'createTenant']);
            Route::post('/superadmin/stores/{store}/toggle-license', [\App\Http\Controllers\Api\SuperadminController::class, 'toggleLicense']);
        });

        // Owner-only routes
        Route::middleware('role:owner')->group(function () {
            Route::put('/tenant', [\App\Http\Controllers\Api\TenantController::class, 'updateTenant']);
            Route::post('/tenant/stores', [\App\Http\Controllers\Api\TenantController::class, 'createStore']);
        });
    });
});
