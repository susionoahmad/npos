<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $tenant = Tenant::create([
                'name' => $payload['company_name']
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
}
