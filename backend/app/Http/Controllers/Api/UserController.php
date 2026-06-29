<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

use Illuminate\Http\Request;

use App\Models\Store;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = User::with('store');

        if ($user->role === 'owner') {
            $query->where('tenant_id', $user->tenant_id);
            if ($user->store_id) {
                $query->where('store_id', $user->store_id);
            } elseif ($request->filled('store_id')) {
                $query->where('store_id', $request->store_id);
            }
        } elseif ($user->role === 'admin') {
            $query->where('store_id', $user->store_id)
                ->where(function ($q) use ($user) {
                    $q->where('id', $user->id)
                      ->orWhere('role', 'cashier');
                });
        } else {
            $query->where('store_id', $user->store_id);
        }

        return $query->latest()->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        $payload = $request->validated();
        $user = auth()->user();

        // Enforce user limit based on subscription
        $tenant = \App\Models\Tenant::find($user->tenant_id);
        if ($tenant) {
            if (!$tenant->isBaseSlotActive()) {
                return response()->json([
                    'message' => 'Masa langganan dasar Anda telah berakhir. Silakan lakukan pembayaran perpanjangan terlebih dahulu.',
                ], 403);
            }
            $maxUsers = $tenant->subscription_status === 'trial' ? ($tenant->max_users ?? 3) : 100;
            if ($tenant->users()->count() >= $maxUsers) {
                return response()->json([
                    'message' => 'Batas maksimal pengguna terlampaui. Silakan perbarui langganan Anda.',
                ], 403);
            }
        }

        // If owner, validate that the chosen store belongs to their tenant
        if ($user->role === 'owner') {
            if (!empty($payload['store_id'])) {
                abort_unless(
                    Store::where('tenant_id', $user->tenant_id)->where('id', $payload['store_id'])->exists(),
                    403,
                    'Store does not belong to your tenant.'
                );
            } else {
                $payload['store_id'] = $user->store_id; // Default to owner's active store
            }
            $payload['tenant_id'] = $user->tenant_id;
        } else {
            if ($user->role === 'admin') {
                abort_unless($payload['role'] === 'cashier', 403, 'Admin can only create cashier users.');
            }
            // Admin can only create users in their own store & tenant
            $payload['tenant_id'] = $user->tenant_id;
            $payload['store_id'] = $user->store_id;
        }

        $payload['password'] = Hash::make($payload['password']);
        return User::create($payload);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = auth()->user();
        if ($user->role === 'owner') {
            return User::where('tenant_id', $user->tenant_id)->findOrFail($id);
        } elseif ($user->role === 'admin') {
            return User::where('store_id', $user->store_id)
                ->where(function ($q) use ($user) {
                    $q->where('id', $user->id)
                      ->orWhere('role', 'cashier');
                })
                ->findOrFail($id);
        }
        return User::where('store_id', $user->store_id)->findOrFail($id);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $currentUser = auth()->user();
        if ($currentUser->role === 'owner') {
            abort_unless($user->tenant_id === $currentUser->tenant_id, 403);
        } else {
            abort_unless($user->store_id === $currentUser->store_id, 403);
            if ($currentUser->role === 'admin') {
                abort_unless(
                    $user->id === $currentUser->id || $user->role === 'cashier',
                    403,
                    'You are not authorized to update this user.'
                );
            }
        }

        $payload = $request->validated();

        if ($currentUser->role === 'admin') {
            // Admin cannot change store_id or tenant_id
            unset($payload['store_id']);
            unset($payload['tenant_id']);

            // Admin cannot change their own role, and can only set others' role to cashier
            if (isset($payload['role'])) {
                if ($user->id === $currentUser->id) {
                    unset($payload['role']);
                } else {
                    abort_unless($payload['role'] === 'cashier', 403, 'You can only set role to cashier.');
                }
            }
        }

        if ($currentUser->role === 'owner' && !empty($payload['store_id'])) {
            abort_unless(
                Store::where('tenant_id', $currentUser->tenant_id)->where('id', $payload['store_id'])->exists(),
                403,
                'Store does not belong to your tenant.'
            );
        }

        if (! empty($payload['password'])) {
            $payload['password'] = Hash::make($payload['password']);
        } else {
            unset($payload['password']);
        }

        $user->update($payload);
        return $user->refresh();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $currentUser = auth()->user();
        if ($currentUser->role === 'owner') {
            abort_unless($user->tenant_id === $currentUser->tenant_id, 403);
        } else {
            abort_unless($user->store_id === $currentUser->store_id, 403);
            if ($currentUser->role === 'admin') {
                abort_unless(
                    $user->id === $currentUser->id || $user->role === 'cashier',
                    403,
                    'You are not authorized to delete this user.'
                );
            }
        }

        $user->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
