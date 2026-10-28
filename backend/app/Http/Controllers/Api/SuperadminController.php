<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\User;
use App\Models\TenantSubscriptionSlot;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SuperadminController extends Controller
{
    /**
     * Helper to sync all expired tenants and their stores
     */
    public static function syncExpiredTenants(): void
    {
        $now = now();

        // 1. Tenant dengan status active yang subscription_ends_at sudah lewat
        $expiredActiveTenants = Tenant::where('subscription_status', 'active')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', $now)
            ->get();

        foreach ($expiredActiveTenants as $tenant) {
            $tenant->update(['subscription_status' => 'expired']);
            $tenant->stores()->update(['is_license_activated' => false]);
            $tenant->subscriptionSlots()
                ->where('status', 'active')
                ->where('expires_at', '<', $now)
                ->update(['status' => 'expired']);
        }

        // 2. Tenant dengan status trial yang trial_ends_at sudah lewat
        $expiredTrialTenants = Tenant::where('subscription_status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $now)
            ->get();

        foreach ($expiredTrialTenants as $tenant) {
            $tenant->update(['subscription_status' => 'expired']);
            $tenant->stores()->update(['is_license_activated' => false]);
        }

        // 3. Slot langganan lama yang telah kedaluwarsa
        TenantSubscriptionSlot::where('status', 'active')
            ->where('expires_at', '<', $now)
            ->update(['status' => 'expired']);
    }

    /**
     * Get list of all tenants with counts and relations
     */
    public function getTenants()
    {
        self::syncExpiredTenants();

        return Tenant::with([
            'stores' => function ($query) {
                $query->withCount('users')->with(['users:id,store_id,tenant_id,name,email,role,is_active']);
            },
            'users:id,tenant_id,store_id,name,email,role,is_active',
            'subscriptionPlan'
        ])
        ->withCount(['stores', 'users'])
        ->get();
    }

    /**
     * Create a new tenant, owner, and initial store
     */
    public function createTenant(Request $request)
    {
        $payload = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'unique:users,email'],
            'owner_password' => ['required', 'string', 'min:6'],
            'store_name' => ['required', 'string', 'max:120'],
            'plan' => ['nullable', 'string', 'max:50'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
        ]);

        $result = DB::transaction(function () use ($payload) {
            $trialDays = (int) SystemSetting::getVal('subscription_trial_days', 14);
            $planSlug = $payload['plan'] ?? 'starter';
            $plan = SubscriptionPlan::where('slug', $planSlug)->first();

            $maxStores = $plan ? $plan->max_stores : (int) SystemSetting::getVal('subscription_trial_stores_limit', 1);
            $maxUsers = $plan ? $plan->max_users : (int) SystemSetting::getVal('subscription_trial_users_limit', 3);

            // 1. Create Tenant
            $tenant = Tenant::create([
                'name' => $payload['company_name'],
                'plan' => $planSlug,
                'billing_cycle' => $payload['billing_cycle'] ?? 'monthly',
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays),
                'max_stores' => $maxStores,
                'max_users' => $maxUsers,
            ]);

            // 2. Create Store
            $store = Store::create([
                'tenant_id' => $tenant->id,
                'name' => $payload['store_name'],
                'currency' => 'IDR',
                'is_license_activated' => true,
                'license_activated_at' => now(),
            ]);

            // 3. Create Owner User
            $owner = User::create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'name' => $payload['owner_name'],
                'email' => $payload['owner_email'],
                'password' => bcrypt($payload['owner_password']),
                'role' => 'owner',
                'is_active' => true,
            ]);

            return [
                'tenant' => $tenant,
                'owner' => $owner,
                'store' => $store,
            ];
        });

        return response()->json([
            'message' => 'Tenant berhasil dibuat.',
            'data' => $result,
        ], 201);
    }

    /**
     * Toggle the license status of a store
     */
    public function toggleLicense($storeId)
    {
        $store = Store::findOrFail($storeId);
        $newStatus = !$store->is_license_activated;
        $store->update([
            'is_license_activated' => $newStatus,
            'license_activated_at' => $newStatus ? now() : null,
        ]);

        return response()->json([
            'message' => 'Lisensi toko berhasil diperbarui.',
            'store' => $store,
        ]);
    }

    /**
     * Get SaaS subscription settings (legacy)
     */
    public function getSettings()
    {
        return response()->json([
            'subscription_base_fee' => (int) SystemSetting::getVal('subscription_base_fee', 100000),
            'subscription_store_addon_fee' => (int) SystemSetting::getVal('subscription_store_addon_fee', 50000),
            'subscription_free_stores_limit' => (int) SystemSetting::getVal('subscription_free_stores_limit', 1),
            'subscription_trial_days' => (int) SystemSetting::getVal('subscription_trial_days', 14),
            'subscription_trial_stores_limit' => (int) SystemSetting::getVal('subscription_trial_stores_limit', 1),
            'subscription_trial_users_limit' => (int) SystemSetting::getVal('subscription_trial_users_limit', 3),
        ]);
    }

    /**
     * Update SaaS subscription settings (legacy)
     */
    public function updateSettings(Request $request)
    {
        $payload = $request->validate([
            'subscription_base_fee' => ['required', 'integer', 'min:0'],
            'subscription_store_addon_fee' => ['required', 'integer', 'min:0'],
            'subscription_free_stores_limit' => ['required', 'integer', 'min:0'],
            'subscription_trial_days' => ['required', 'integer', 'min:1'],
            'subscription_trial_stores_limit' => ['required', 'integer', 'min:1'],
            'subscription_trial_users_limit' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($payload as $key => $value) {
            SystemSetting::setVal($key, $value);
        }

        return response()->json([
            'message' => 'Pengaturan berlangganan berhasil diperbarui.',
            'settings' => $payload
        ]);
    }

    /**
     * Get all subscription plans with tenant subscriber counts
     */
    public function getPlans()
    {
        $plans = SubscriptionPlan::orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($plan) {
                $subscribersCount = Tenant::where('plan', $plan->slug)->count();
                $activeSubscribersCount = Tenant::where('plan', $plan->slug)
                    ->whereIn('subscription_status', ['trial', 'active'])
                    ->count();

                return array_merge($plan->toArray(), [
                    'subscribers_count' => $subscribersCount,
                    'active_subscribers_count' => $activeSubscribersCount,
                ]);
            });

        return response()->json([
            'status' => 'success',
            'data' => $plans,
        ]);
    }

    /**
     * Store a new subscription plan
     */
    public function storePlan(Request $request)
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:subscription_plans,slug'],
            'name' => ['required', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'min:0'],
            'max_stores' => ['required', 'integer', 'min:1'],
            'max_users' => ['required', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $plan = SubscriptionPlan::create([
            'slug' => Str::slug($validated['slug']),
            'name' => $validated['name'],
            'tagline' => $validated['tagline'] ?? null,
            'price_monthly' => $validated['price_monthly'],
            'price_yearly' => $validated['price_yearly'],
            'max_stores' => $validated['max_stores'],
            'max_users' => $validated['max_users'],
            'features' => $validated['features'] ?? [],
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Paket langganan baru berhasil dibuat.',
            'data' => $plan,
        ], 201);
    }

    /**
     * Update an existing subscription plan
     */
    public function updatePlan(Request $request, $id)
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $validated = $request->validate([
            'slug' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('subscription_plans', 'slug')->ignore($plan->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'min:0'],
            'max_stores' => ['required', 'integer', 'min:1'],
            'max_users' => ['required', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $plan->update([
            'slug' => Str::slug($validated['slug']),
            'name' => $validated['name'],
            'tagline' => $validated['tagline'] ?? null,
            'price_monthly' => $validated['price_monthly'],
            'price_yearly' => $validated['price_yearly'],
            'max_stores' => $validated['max_stores'],
            'max_users' => $validated['max_users'],
            'features' => $validated['features'] ?? [],
            'is_active' => $validated['is_active'] ?? $plan->is_active,
            'sort_order' => $validated['sort_order'] ?? $plan->sort_order,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Paket langganan berhasil diperbarui.',
            'data' => $plan,
        ]);
    }

    /**
     * Delete or deactivate a subscription plan
     */
    public function deletePlan($id)
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $activeSubscribersCount = Tenant::where('plan', $plan->slug)
            ->whereIn('subscription_status', ['trial', 'active'])
            ->count();

        if ($activeSubscribersCount > 0) {
            $plan->update(['is_active' => false]);

            return response()->json([
                'status' => 'success',
                'message' => "Paket '{$plan->name}' dinonaktifkan karena masih digunakan oleh {$activeSubscribersCount} tenant.",
                'data' => $plan,
            ]);
        }

        $planName = $plan->name;
        $plan->delete();

        return response()->json([
            'status' => 'success',
            'message' => "Paket '{$planName}' berhasil dihapus.",
        ]);
    }

    /**
     * Update tenant subscription details manually (Superadmin)
     */
    public function updateTenantSubscription(Request $request, $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $payload = $request->validate([
            'subscription_status' => ['required', 'in:trial,active,expired,cancelled'],
            'plan' => ['nullable', 'string', 'max:50'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
            'trial_ends_at' => ['nullable', 'date'],
            'subscription_ends_at' => ['nullable', 'date'],
            'max_stores' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],
        ]);

        // Jika plan diganti dan limit tidak diisi manual, sesuaikan dengan plan
        if (!empty($payload['plan'])) {
            $planModel = SubscriptionPlan::where('slug', $payload['plan'])->first();
            if ($planModel) {
                if (empty($payload['max_stores'])) {
                    $payload['max_stores'] = $planModel->max_stores;
                }
                if (empty($payload['max_users'])) {
                    $payload['max_users'] = $planModel->max_users;
                }
            }
        }

        $tenant->update(array_filter($payload, fn($val) => !is_null($val)));

        // Jika status diubah menjadi expired atau cancelled, nonaktifkan lisensi toko
        if (in_array($payload['subscription_status'], ['expired', 'cancelled'])) {
            $tenant->stores()->update(['is_license_activated' => false]);
            $tenant->subscriptionSlots()->update(['status' => 'expired']);
        } elseif ($payload['subscription_status'] === 'active' || $payload['subscription_status'] === 'trial') {
            // Aktifkan lisensi seluruh toko hingga batas max_stores
            $stores = $tenant->stores()->orderBy('id')->get();
            $limit = $tenant->max_stores ?: 1;
            foreach ($stores as $index => $store) {
                $shouldActive = ($index < $limit);
                $store->update([
                    'is_license_activated' => $shouldActive,
                    'license_activated_at' => $shouldActive ? ($store->license_activated_at ?: now()) : null,
                ]);
            }
        }

        return response()->json([
            'message' => 'Detail berlangganan tenant berhasil diperbarui.',
            'tenant' => $tenant->fresh(['stores', 'users', 'subscriptionPlan'])
        ]);
    }
}