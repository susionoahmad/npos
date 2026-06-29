<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\SystemSetting;

class SuperadminController extends Controller
{
    /**
     * Get list of all tenants with counts and relations
     */
    public function getTenants()
    {
        return Tenant::with(['stores', 'users'])
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
        ]);

        $result = DB::transaction(function () use ($payload) {
            // 1. Create Tenant
            $trialDays = (int) SystemSetting::getVal('subscription_trial_days', 14);
            $trialStores = (int) SystemSetting::getVal('subscription_trial_stores_limit', 1);
            $trialUsers = (int) SystemSetting::getVal('subscription_trial_users_limit', 3);

            $tenant = Tenant::create([
                'name' => $payload['company_name'],
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays),
                'max_stores' => $trialStores,
                'max_users' => $trialUsers,
            ]);

            // 2. Create Store
            $store = Store::create([
                'tenant_id' => $tenant->id,
                'name' => $payload['store_name'],
                'currency' => 'IDR',
                'is_license_activated' => false,
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
     * Get SaaS subscription settings
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
     * Update SaaS subscription settings
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
     * Update tenant subscription details manually (Superadmin)
     */
    public function updateTenantSubscription(Request $request, $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $payload = $request->validate([
            'subscription_status' => ['required', 'in:trial,active,expired,cancelled'],
            'trial_ends_at' => ['nullable', 'date'],
            'subscription_ends_at' => ['nullable', 'date'],
            'max_stores' => ['required', 'integer', 'min:1'],
            'max_users' => ['required', 'integer', 'min:1'],
        ]);

        $tenant->update($payload);

        return response()->json([
            'message' => 'Detail berlangganan tenant berhasil diperbarui.',
            'tenant' => $tenant->fresh(['stores', 'users'])
        ]);
    }
}
