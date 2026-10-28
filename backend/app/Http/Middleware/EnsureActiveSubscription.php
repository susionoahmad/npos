<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Superadmin bypass
        if (!$user || $user->role === 'superadmin') {
            return $next($request);
        }

        $tenant = $user->tenant;

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant tidak ditemukan untuk akun ini.',
                'error_code' => 'TENANT_NOT_FOUND'
            ], 403);
        }

        // Auto sync if expired by date
        $tenant->checkAndSyncExpiredStatus();

        if ($tenant->subscription_status === 'expired' || !$tenant->isBaseSlotActive()) {
            return response()->json([
                'message' => 'Masa langganan toko Anda telah berakhir. Harap perpanjang langganan di menu Tagihan untuk melanjutkan transaksi dan operasional.',
                'error_code' => 'SUBSCRIPTION_EXPIRED'
            ], 403);
        }

        // Check store license
        $store = $user->store;
        if ($store && !$store->is_license_activated) {
            return response()->json([
                'message' => 'Lisensi toko ini tidak aktif atau telah dinonaktifkan. Hubungi pemilik akun (Owner) untuk mengaktifkan lisensi.',
                'error_code' => 'STORE_LICENSE_INACTIVE'
            ], 403);
        }

        return $next($request);
    }
}