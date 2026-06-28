<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function show()
    {
        return auth()->user()->store;
    }

    public function activateLicense(Request $request)
    {
        $user = auth()->user();
        $storeId = $request->input('store_id') ?: $user->store_id;
        abort_unless($storeId, 422, 'Pilih toko tertentu untuk mengaktifkan lisensi.');

        $store = \App\Models\Store::findOrFail($storeId);

        if ($user->role !== 'superadmin') {
            abort_unless($store->tenant_id === $user->tenant_id, 403);
        }

        if ($store->is_license_activated) {
            return response()->json(['message' => 'License already activated'], 422);
        }

        $store->update([
            'is_license_activated' => true,
            'license_activated_at' => now(),
        ]);

        return $store->refresh();
    }
}
