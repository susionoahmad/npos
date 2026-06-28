<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Supplier::whereIn('store_id', auth()->user()->getAccessibleStoreIds())->latest()->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $storeId = auth()->user()->store_id;
        if (auth()->user()->role === 'owner' && !$storeId) {
            return response()->json(['message' => 'Please select a specific store first to create a supplier.'], 400);
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string'],
        ]);
        $payload['store_id'] = $storeId;
        return Supplier::create($payload);
    }

    /**
     * Display the specified resource.
     */
    public function show(Supplier $supplier)
    {
        abort_unless(in_array($supplier->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        return $supplier;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Supplier $supplier)
    {
        abort_unless(in_array($supplier->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        $supplier->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string'],
        ]));
        return $supplier;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Supplier $supplier)
    {
        abort_unless(in_array($supplier->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        $supplier->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
