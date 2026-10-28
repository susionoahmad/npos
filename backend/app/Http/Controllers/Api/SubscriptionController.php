<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscriptionSlot;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * Get billing overview: current subscription plan, available plans, and usage limits.
     */
    public function getBillingOverview()
    {
        $user = auth()->user();
        $tenant = $user->tenant;

        if (!$tenant) {
            return response()->json(['message' => 'Tenant tidak ditemukan.'], 404);
        }

        // Sync expired status if needed
        $tenant->checkAndSyncExpiredStatus();

        // Load all active subscription plans
        $plans = SubscriptionPlan::active()
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Determine current plan details
        $currentPlanSlug = $tenant->plan ?: 'starter';
        $currentPlan = SubscriptionPlan::where('slug', $currentPlanSlug)->first();

        // Determine effective limits based on the assigned plan
        $effectiveMaxStores = $currentPlan ? $currentPlan->max_stores : ($tenant->max_stores ?: 1);
        $effectiveMaxUsers  = $currentPlan ? $currentPlan->max_users : ($tenant->max_users ?: 3);

        // Auto-heal / sync database tenant limits if out of sync with current plan
        if ($currentPlan && ($tenant->max_stores != $effectiveMaxStores || $tenant->max_users != $effectiveMaxUsers)) {
            $tenant->update([
                'max_stores' => $effectiveMaxStores,
                'max_users'  => $effectiveMaxUsers,
            ]);
        }

        $currentStores = $tenant->stores()->count();
        $activeStores  = $tenant->stores()->where('is_license_activated', true)->count();
        $currentUsers  = $tenant->users()->count();

        // Calculate days remaining
        $daysRemaining = 0;
        $now = now();
        if ($tenant->subscription_status === 'active' && $tenant->subscription_ends_at) {
            $daysRemaining = max(0, (int) $now->diffInDays($tenant->subscription_ends_at, false));
        } elseif ($tenant->subscription_status === 'trial' && $tenant->trial_ends_at) {
            $daysRemaining = max(0, (int) $now->diffInDays($tenant->trial_ends_at, false));
        }

        $isActive = in_array($tenant->subscription_status, ['trial', 'active']) && $daysRemaining >= 0;

        return response()->json([
            'tenant'          => $tenant,
            'current_plan'    => $currentPlan,
            'plans'           => $plans,
            'current_stores'  => $currentStores,
            'active_stores'   => $activeStores,
            'max_stores'      => $effectiveMaxStores,
            'current_users'   => $currentUsers,
            'max_users'       => $effectiveMaxUsers,
            'days_remaining'  => $daysRemaining,
            'is_active'       => $isActive,
        ]);
    }

    /**
     * Create Midtrans Snap checkout session for a subscription plan (Monthly or Yearly).
     */
    public function checkout(Request $request)
    {
        $user = auth()->user();
        $tenant = $user->tenant;
        abort_unless($user->role === 'owner', 403, 'Hanya Owner yang dapat mengelola dan membeli paket langganan.');

        if (!$tenant) {
            return response()->json(['message' => 'Tenant tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'plan'          => ['required', 'string', 'exists:subscription_plans,slug'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ]);

        $planModel = SubscriptionPlan::where('slug', $validated['plan'])->firstOrFail();
        $cycle = $validated['billing_cycle'];
        $fee = ($cycle === 'yearly') ? (int) $planModel->price_yearly : (int) $planModel->price_monthly;

        $serverKey    = config('services.midtrans.server_key');
        $isProduction = config('services.midtrans.is_production', false);
        $snapUrl      = $isProduction
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        if (!$serverKey) {
            return response()->json(['message' => 'Konfigurasi pembayaran Midtrans belum diatur.'], 500);
        }

        $cycleLabel = ($cycle === 'yearly') ? '1 Tahun' : '1 Bulan';
        $itemLabel  = 'Paket ' . $planModel->name . ' (' . $cycleLabel . ')';
        // Order ID format: SUB_{tenantId}_{PLAN}_{CYCLE}_{timestamp}
        $orderId = 'SUB_' . $tenant->id . '_' . strtoupper($planModel->slug) . '_' . strtoupper($cycle) . '_' . time();

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
                    'id'       => $planModel->slug . '_' . $cycle,
                    'price'    => $fee,
                    'quantity' => 1,
                    'name'     => mb_substr($itemLabel, 0, 50),
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
     * Midtrans Webhook Callback.
     * Order ID formats:
     *   New Plan format: SUB_{tenant_id}_{PLAN}_{CYCLE}_{ts}
     *   Legacy slot format: SUB_{tenant_id}_RENEW_{slot_id}_{ts} | SUB_{tenant_id}_ADDON_{slot_index}_{ts}
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

        $tenantId = (int) $parts[1];
        $tenant   = Tenant::find($tenantId);

        if (!$tenant) {
            Log::error('Midtrans: Tenant not found:', ['tenant_id' => $tenantId]);
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        // Process successful payment
        if ($transactionStatus === 'settlement' ||
            ($transactionStatus === 'capture' && $fraudStatus !== 'challenge')) {

            // Check if this is a Plan-based order: SUB_{tenantId}_{PLAN}_{CYCLE}_{timestamp}
            if (count($parts) >= 5) {
                $planSlug = strtolower($parts[2]);
                $cycle    = strtolower($parts[3]);

                $planModel = SubscriptionPlan::where('slug', $planSlug)->first();

                // Base date for extension: if currently active and ends_at is in future, add from ends_at
                $baseDate = ($tenant->subscription_status === 'active' && $tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture())
                    ? $tenant->subscription_ends_at->copy()
                    : now();

                $newEndsAt = ($cycle === 'yearly')
                    ? $baseDate->copy()->addYear()
                    : $baseDate->copy()->addMonth();

                $maxStores = $planModel ? $planModel->max_stores : max(1, $tenant->max_stores);
                $maxUsers  = $planModel ? $planModel->max_users : max(3, $tenant->max_users);

                $tenant->update([
                    'plan'                 => $planSlug,
                    'billing_cycle'        => $cycle,
                    'subscription_status'  => 'active',
                    'subscription_ends_at' => $newEndsAt,
                    'max_stores'           => $maxStores,
                    'max_users'            => $maxUsers,
                ]);

                // Automatically activate licenses for tenant stores up to max_stores
                $stores = $tenant->stores()->orderBy('id')->get();
                foreach ($stores as $idx => $store) {
                    $shouldActivate = ($idx < $maxStores);
                    $store->update([
                        'is_license_activated' => $shouldActivate,
                        'license_activated_at' => $shouldActivate ? ($store->license_activated_at ?: now()) : null,
                    ]);
                }

                Log::info('Tenant subscription plan upgraded/renewed:', [
                    'tenant_id'     => $tenantId,
                    'plan'          => $planSlug,
                    'cycle'         => $cycle,
                    'ends_at'       => $newEndsAt->toDateTimeString(),
                    'max_stores'    => $maxStores,
                ]);

            } else {
                // Legacy Slot-based fallback
                $action = $parts[2]; // RENEW or ADDON
                $refId  = (int) $parts[3];

                $baseFee  = (int) SystemSetting::getVal('subscription_base_fee', 100000);
                $addonFee = (int) SystemSetting::getVal('subscription_store_addon_fee', 50000);
                $newStart = now();
                $newEnd   = now()->addDays(30);

                if ($action === 'RENEW') {
                    $slot = TenantSubscriptionSlot::where('tenant_id', $tenantId)->find($refId);
                    if ($slot) {
                        $newStart = $slot->isActive() ? $slot->expires_at : now();
                        $newEnd   = $newStart->copy()->addDays(30);
                        $slot->update([
                            'starts_at'          => $newStart,
                            'expires_at'         => $newEnd,
                            'status'             => 'active',
                            'amount_paid'        => $slot->slot_type === 'base' ? $baseFee : $addonFee,
                            'midtrans_order_id'  => $orderId,
                        ]);
                    }
                } elseif ($action === 'ADDON') {
                    $isBase = ((int)$refId === 1);
                    TenantSubscriptionSlot::updateOrCreate(
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
                }

                $activeSlots = TenantSubscriptionSlot::where('tenant_id', $tenantId)->active()->count();
                $tenant->update([
                    'subscription_status'  => 'active',
                    'subscription_ends_at' => $newEnd,
                    'max_stores'          => max(1, $activeSlots),
                ]);
            }

        } elseif (in_array($transactionStatus, ['expire', 'cancel', 'deny'])) {
            Log::info('Midtrans subscription payment not completed:', [
                'status'   => $transactionStatus,
                'order_id' => $orderId,
            ]);
        }

        return response()->json(['status' => 'OK']);
    }
}