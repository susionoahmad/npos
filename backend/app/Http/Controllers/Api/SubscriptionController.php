<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSubscriptionSlot;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * Get billing overview: slots list + pricing config
     */
    public function getBillingOverview()
    {
        $user = auth()->user();
        $tenant = $user->tenant;

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        $baseFee   = (int) SystemSetting::getVal('subscription_base_fee', 100000);
        $addonFee  = (int) SystemSetting::getVal('subscription_store_addon_fee', 50000);
        $freeLimit = (int) SystemSetting::getVal('subscription_free_stores_limit', 1);

        $currentStores = $tenant->stores()->where('is_license_activated', true)->count();
        $currentUsers  = $tenant->users()->count();

        // Get all slots with status
        $slots = $tenant->subscriptionSlots()
            ->orderBy('slot_index')
            ->get()
            ->map(fn($slot) => [
                'id'          => $slot->id,
                'slot_index'  => $slot->slot_index,
                'slot_type'   => $slot->slot_type,
                'status'      => $slot->isActive() ? 'active' : 'expired',
                'expires_at'  => $slot->expires_at?->toDateTimeString(),
                'days_remaining' => $slot->daysRemaining(),
                'amount_paid' => $slot->amount_paid,
                'label'       => $slot->slot_type === 'base'
                    ? 'Paket Dasar (1 Toko)'
                    : 'Slot Toko Tambahan #' . ($slot->slot_index - 1),
                'renew_fee'   => $slot->slot_type === 'base' ? $baseFee : $addonFee,
            ]);

        return response()->json([
            'tenant'         => $tenant,
            'slots'          => $slots,
            'current_stores' => $currentStores,
            'current_users'  => $currentUsers,
            'pricing_config' => [
                'base_fee'          => $baseFee,
                'store_addon_fee'   => $addonFee,
                'free_stores_limit' => $freeLimit,
            ],
        ]);
    }

    /**
     * Create Midtrans Snap checkout for a specific slot action:
     * - action=renew&slot_id=X → perpanjang slot tertentu
     * - action=add_addon        → tambah slot toko baru
     */
    public function checkout(Request $request)
    {
        $user   = auth()->user();
        $tenant = $user->tenant;
        abort_unless($user->role === 'owner', 403, 'Hanya Owner yang dapat mengelola tagihan.');

        $request->validate([
            'action'  => ['required', 'in:renew,add_addon'],
            'slot_id' => ['required_if:action,renew', 'nullable', 'integer'],
        ]);

        $baseFee  = (int) SystemSetting::getVal('subscription_base_fee', 100000);
        $addonFee = (int) SystemSetting::getVal('subscription_store_addon_fee', 50000);

        $serverKey    = config('services.midtrans.server_key');
        $isProduction = config('services.midtrans.is_production', false);
        $snapUrl      = $isProduction
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        if (!$serverKey) {
            return response()->json(['message' => 'Konfigurasi pembayaran belum diatur.'], 500);
        }

        if ($request->action === 'renew') {
            // Perpanjang slot yang sudah ada
            $slot = TenantSubscriptionSlot::where('tenant_id', $tenant->id)
                ->findOrFail($request->slot_id);

            $fee     = $slot->slot_type === 'base' ? $baseFee : $addonFee;
            $label   = $slot->slot_type === 'base'
                ? 'Perpanjang Paket Dasar (1 Toko)'
                : 'Perpanjang Slot Toko #' . ($slot->slot_index - 1);
            $orderId = 'SUB_' . $tenant->id . '_RENEW_' . $slot->id . '_' . time();

        } else {
            // Tambah slot baru (base jika pertama, addon jika berikutnya)
            $nextIdx = $tenant->nextSlotIndex();
            $isBase  = ($nextIdx === 1);
            $fee     = $isBase ? $baseFee : $addonFee;
            $label   = $isBase ? 'Aktivasi Paket Dasar (1 Toko)' : 'Slot Toko Tambahan Baru';
            $orderId = 'SUB_' . $tenant->id . '_ADDON_' . $nextIdx . '_' . time();
        }

        try {
            $response = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($serverKey . ':'),
            ])->post($snapUrl, [
                'transaction_details' => [
                    'order_id'     => $orderId,
                    'gross_amount' => $fee,
                ],
                'customer_details' => [
                    'first_name' => $user->name,
                    'email'      => $user->email,
                ],
                'item_details' => [[
                    'id'       => 'slot_payment',
                    'price'    => $fee,
                    'quantity' => 1,
                    'name'     => $label,
                ]],
            ]);

            if ($response->failed()) {
                Log::error('Midtrans Snap Failed:', $response->json() ?: [$response->body()]);
                return response()->json(['message' => 'Gagal membuat sesi pembayaran Midtrans.'], 500);
            }

            return response()->json(array_merge($response->json(), ['order_id' => $orderId]));

        } catch (\Exception $e) {
            Log::error('Midtrans Exception:', [$e->getMessage()]);
            return response()->json(['message' => 'Terjadi kesalahan server saat menghubungi Midtrans.'], 500);
        }
    }

    /**
     * Midtrans Webhook Callback (per-slot)
     * Order ID formats:
     *   SUB_{tenant_id}_RENEW_{slot_id}_{ts}
     *   SUB_{tenant_id}_ADDON_{slot_index}_{ts}
     */
    public function callback(Request $request)
    {
        $payload           = $request->all();
        $orderId           = $payload['order_id'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus       = $payload['fraud_status'] ?? null;
        $signatureKey      = $payload['signature_key'] ?? null;
        $statusCode        = $payload['status_code'] ?? null;
        $grossAmount       = $payload['gross_amount'] ?? null;

        if (!$orderId || !$signatureKey) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        // Validate signature
        $serverKey      = config('services.midtrans.server_key');
        $localSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if ($localSignature !== $signatureKey) {
            Log::warning('Midtrans Invalid Signature:', ['order_id' => $orderId]);
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        // Parse order_id
        $parts = explode('_', $orderId);
        if (count($parts) < 4 || $parts[0] !== 'SUB') {
            Log::info('Midtrans: Ignored non-subscription order:', ['order_id' => $orderId]);
            return response()->json(['message' => 'Ignored.']);
        }

        $tenantId  = (int) $parts[1];
        $action    = $parts[2]; // RENEW or ADDON
        $refId     = (int) $parts[3]; // slot_id (RENEW) or slot_index (ADDON)

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            Log::error('Midtrans: Tenant not found:', ['tenant_id' => $tenantId]);
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        // Process payment
        if ($transactionStatus === 'settlement' ||
            ($transactionStatus === 'capture' && $fraudStatus !== 'challenge')) {

            $baseFee  = (int) SystemSetting::getVal('subscription_base_fee', 100000);
            $addonFee = (int) SystemSetting::getVal('subscription_store_addon_fee', 50000);
            $newStart = now();
            $newEnd   = now()->addDays(30);

            if ($action === 'RENEW') {
                // Perpanjang slot yang sudah ada
                $slot = TenantSubscriptionSlot::where('tenant_id', $tenantId)->find($refId);
                if ($slot) {
                    // Extend from current expiry if still active
                    $newStart = $slot->isActive() ? $slot->expires_at : now();
                    $newEnd   = $newStart->copy()->addDays(30);

                    $slot->update([
                        'starts_at'          => $newStart,
                        'expires_at'         => $newEnd,
                        'status'             => 'active',
                        'amount_paid'        => $slot->slot_type === 'base' ? $baseFee : $addonFee,
                        'midtrans_order_id'  => $orderId,
                    ]);

                    Log::info('Slot renewed:', [
                        'tenant_id'  => $tenantId,
                        'slot_id'    => $slot->id,
                        'expires_at' => $newEnd->toDateTimeString(),
                    ]);
                }

            } elseif ($action === 'ADDON') {
                // Buat slot baru (base jika index 1, addon jika > 1)
                $isBase = ((int)$refId === 1);
                $slot = TenantSubscriptionSlot::updateOrCreate(
                    ['tenant_id' => $tenantId, 'slot_index' => $refId],
                    [
                        'slot_type'         => $isBase ? 'base' : 'addon',
                        'amount_paid'       => $isBase ? $baseFee : $addonFee,
                        'starts_at'         => $newStart,
                        'expires_at'        => $newEnd,
                        'status'            => 'active',
                        'midtrans_order_id' => $orderId,
                    ]
                );

                Log::info('Addon slot activated:', [
                    'tenant_id'   => $tenantId,
                    'slot_index'  => $refId,
                    'expires_at'  => $newEnd->toDateTimeString(),
                ]);
            }

            // Update tenant max_stores from active slots
            $activeSlots = TenantSubscriptionSlot::where('tenant_id', $tenantId)->active()->count();
            $tenant->update([
                'subscription_status' => 'active',
                'max_stores'          => max(1, $activeSlots),
                'max_users'           => 100,
            ]);

        } elseif (in_array($transactionStatus, ['expire', 'cancel', 'deny'])) {
            Log::info('Midtrans payment not completed:', [
                'status'   => $transactionStatus,
                'order_id' => $orderId,
            ]);
        }

        return response()->json(['status' => 'OK']);
    }
}
