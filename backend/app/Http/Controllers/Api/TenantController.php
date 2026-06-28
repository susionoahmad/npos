<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function getStores()
    {
        $user = auth()->user();
        abort_unless($user->role === 'owner', 403, 'Only owners can manage tenant stores.');

        return Store::where('tenant_id', $user->tenant_id)->get();
    }

    public function switchStore(Request $request)
    {
        $user = auth()->user();
        abort_unless($user->role === 'owner', 403, 'Only owners can switch stores.');

        $request->validate([
            'store_id' => ['nullable', 'exists:stores,id'],
        ]);

        $storeId = $request->input('store_id');

        if ($storeId) {
            // Verify the store belongs to the owner's tenant
            $store = Store::where('tenant_id', $user->tenant_id)->findOrFail($storeId);
            $user->update([
                'store_id' => $store->id,
            ]);
            $storeData = $store;
        } else {
            $user->update([
                'store_id' => null,
            ]);
            $storeData = null;
        }

        return response()->json([
            'message' => 'Active store switched successfully.',
            'store' => $storeData,
            'user' => $user->fresh('store'),
        ]);
    }

    public function updateTenant(Request $request)
    {
        $user = auth()->user();
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $tenant = $user->tenant;
        $tenant->update($payload);
        return response()->json([
            'message' => 'Profil perusahaan berhasil diperbarui.',
            'tenant' => $tenant,
        ]);
    }

    public function createStore(Request $request)
    {
        $user = auth()->user();
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:32'],
            'currency' => ['required', 'string', 'max:8'],
        ]);

        $store = Store::create([
            'tenant_id' => $user->tenant_id,
            'name' => $payload['name'],
            'address' => $payload['address'],
            'phone' => $payload['phone'],
            'currency' => $payload['currency'],
            'is_license_activated' => false,
        ]);

        return response()->json([
            'message' => 'Cabang toko baru berhasil didaftarkan.',
            'store' => $store,
        ], 201);
    }
}
