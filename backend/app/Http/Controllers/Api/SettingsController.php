<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Store;

class SettingsController extends Controller
{
    public function updateStore(Request $request)
    {
        $payload = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:32'],
            'currency' => ['required', 'string', 'max:8'],
            'receipt_footer' => ['nullable', 'string'],
            'print_method' => ['required', 'string', 'in:browser,rawbt'],
        ]);

        $user = auth()->user();
        $storeId = $request->store_id ?: $user->store_id;

        abort_unless($storeId, 422, 'Pilih toko tertentu untuk memperbarui pengaturan.');

        $store = Store::findOrFail($storeId);
        if ($user->role !== 'superadmin') {
            abort_unless($store->tenant_id === $user->tenant_id, 403);
        }

        $store->update(array_diff_key($payload, ['store_id' => 1]));
        return $store->refresh();
    }
}
