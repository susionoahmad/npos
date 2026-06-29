<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * Get billing overview and calculate price
     */
    public function getBillingOverview()
    {
        $user = auth()->user();
        $tenant = $user->tenant;

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        $baseFee = (int) SystemSetting::getVal('subscription_base_fee', 100000);
        $addonFee = (int) SystemSetting::getVal('subscription_store_addon_fee', 50000);
        $freeStores = (int) SystemSetting::getVal('subscription_free_stores_limit', 1);

        $currentStores = $tenant->stores()->count();
        $currentUsers = $tenant->users()->count();

        return response()->json([
            'tenant' => $tenant,
            'current_stores' => $currentStores,
            'current_users' => $currentUsers,
            'pricing_config' => [
                'base_fee' => $baseFee,
                'store_addon_fee' => $addonFee,
                'free_stores_limit' => $freeStores,
            ]
        ]);
    }

    /**
     * Create Midtrans checkout snap token
     */
    public function checkout(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        abort_unless($user->role === 'owner', 403, 'Hanya Owner yang dapat mengelola tagihan.');

        $request->validate([
            'total_stores' => ['required', 'integer', 'min:1'],
        ]);

        $totalStores = (int) $request->total_stores;
        $baseFee = (int) SystemSetting::getVal('subscription_base_fee', 100000);
        $addonFee = (int) SystemSetting::getVal('subscription_store_addon_fee', 50000);
        $freeStores = (int) SystemSetting::getVal('subscription_free_stores_limit', 1);

        // Formula: Base + (additional stores * addon fee)
        $additionalStores = max(0, $totalStores - $freeStores);
        $grossAmount = $baseFee + ($additionalStores * $addonFee);

        $orderId = 'SUB_' . $tenant->id . '_' . $totalStores . '_' . time();

        $serverKey = config('services.midtrans.server_key');
        $isProduction = config('services.midtrans.is_production', false);
        $baseUrl = $isProduction 
            ? 'https://app.midtrans.com/snap/v1/transactions' 
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        if (!$serverKey) {
            Log::error('Midtrans server key not configured in .env (MIDTRANS_SERVER_KEY).');
            return response()->json(['message' => 'Konfigurasi pembayaran belum diatur. Hubungi administrator.'], 500);
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($serverKey . ':'),
            ])->post($baseUrl, [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => $grossAmount,
                ],
                'customer_details' => [
                    'first_name' => $user->name,
                    'email' => $user->email,
                ],
                'item_details' => array_values(array_filter([
                    [
                        'id' => 'base_sub',
                        'price' => $baseFee,
                        'quantity' => 1,
                        'name' => 'Biaya Dasar Langganan (1 Toko)',
                    ],
                    // Only include addon if there are extra stores (Midtrans requires quantity >= 1)
                    $additionalStores > 0 ? [
                        'id' => 'addon_store',
                        'price' => $addonFee,
                        'quantity' => $additionalStores,
                        'name' => 'Toko Tambahan (' . $additionalStores . ' cabang)',
                    ] : null,
                ]))
            ]);

            if ($response->failed()) {
                Log::error('Midtrans Snap Failed:', $response->json() ?: [$response->body()]);
                return response()->json(['message' => 'Gagal membuat sesi pembayaran Midtrans.'], 500);
            }

            return response()->json($response->json());
        } catch (\Exception $e) {
            Log::error('Midtrans Exception:', [$e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan server saat menghubungi Midtrans.'], 500);
        }
    }

    /**
     * Midtrans Notification Webhook Callback
     */
    public function callback(Request $request)
    {
        $payload = $request->all();
        $orderId = $payload['order_id'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus = $payload['fraud_status'] ?? null;
        $signatureKey = $payload['signature_key'] ?? null;
        $statusCode = $payload['status_code'] ?? null;
        $grossAmount = $payload['gross_amount'] ?? null;

        if (!$orderId || !$signatureKey) {
            return response()->json(['message' => 'Invalid notification payload.'], 400);
        }

        // Validate Midtrans Signature Key
        $serverKey = config('services.midtrans.server_key');
        $localSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if ($localSignature !== $signatureKey) {
            Log::warning('Midtrans Invalid Signature Attempted:', ['payload' => $payload]);
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        // Parse order_id: SUB_{tenant_id}_{max_stores}_{timestamp}
        $parts = explode('_', $orderId);
        if (count($parts) < 4 || $parts[0] !== 'SUB') {
            Log::warning('Midtrans Webhook: Ignored non-subscription order_id:', ['order_id' => $orderId]);
            return response()->json(['message' => 'Ignored order.']);
        }

        $tenantId = (int) $parts[1];
        $maxStores = (int) $parts[2];

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            Log::error('Midtrans Webhook Tenant not found:', ['tenant_id' => $tenantId]);
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        // Process payment status
        if ($transactionStatus === 'settlement' || $transactionStatus === 'capture') {
            if ($transactionStatus === 'capture' && $fraudStatus === 'challenge') {
                // Challenged payment, wait for admin action
                Log::info('Midtrans payment challenge:', ['order_id' => $orderId]);
            } else {
                // Paid successfully
                // Extend active subscription by 30 days
                $currentEnd = $tenant->subscription_ends_at ? max(now(), $tenant->subscription_ends_at) : now();
                $newEnd = $currentEnd->copy()->addDays(30);

                // Trial ends immediately or keeps same
                $tenant->update([
                    'subscription_status' => 'active',
                    'subscription_ends_at' => $newEnd,
                    'max_stores' => $maxStores,
                    'max_users' => 100, // Unlimited users during active subscription
                ]);

                Log::info('Tenant subscription updated successfully:', ['tenant_id' => $tenantId, 'new_ends_at' => $newEnd->toDateTimeString()]);
            }
        } elseif ($transactionStatus === 'expire' || $transactionStatus === 'cancel' || $transactionStatus === 'deny') {
            // Payment expired/failed
            // Do not update status here to prevent locking active subscribers if checkout was just a failure/cancelled attempt
            Log::info('Midtrans payment failed status:', ['status' => $transactionStatus, 'order_id' => $orderId]);
        }

        return response()->json(['status' => 'OK']);
    }
}
