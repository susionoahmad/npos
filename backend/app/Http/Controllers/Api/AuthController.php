<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $user = User::with('store')->where('email', $request->email)->first();
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'User account is disabled'], 403);
        }

        $user->tokens()->delete();
        $plainToken = $user->createToken('spa-token')->plainTextToken;
        $token = explode('|', $plainToken)[1] ?? $plainToken;
        $user->update(['active_session_token' => hash('sha256', $token)]);

        return response()->json([
            'token' => $plainToken,
            'user' => $user,
        ]);
    }

    public function logout()
    {
        $user = auth()->user();
        $user?->currentAccessToken()?->delete();
        $user?->update(['active_session_token' => null]);

        return response()->json(['message' => 'Logged out']);
    }

    public function changePassword(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = auth()->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Password saat ini salah.',
                'errors' => [
                    'current_password' => ['Password saat ini salah.']
                ]
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json([
            'message' => 'Password berhasil diperbarui.'
        ]);
    }

    public function register(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $plainToken = $user->createToken('spa-token')->plainTextToken;
        $token = explode('|', $plainToken)[1] ?? $plainToken;
        $user->update(['active_session_token' => hash('sha256', $token)]);

        return response()->json([
            'token' => $plainToken,
            'user' => $user->fresh(['store', 'tenant']),
        ], 201);
    }

    public function setupWizard(\Illuminate\Http\Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'owner' || $user->tenant_id !== null) {
            return response()->json(['message' => 'Setup wizard already completed or user is not an owner.'], 400);
        }

        $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'store_name' => ['required', 'string', 'max:255'],
            'store_address' => ['nullable', 'string'],
            'store_phone' => ['nullable', 'string', 'max:32'],
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $user) {
            $trialDays = (int) \App\Models\SystemSetting::getVal('subscription_trial_days', 14);
            $trialStores = (int) \App\Models\SystemSetting::getVal('subscription_trial_stores_limit', 1);
            $trialUsers = (int) \App\Models\SystemSetting::getVal('subscription_trial_users_limit', 3);

            $tenant = \App\Models\Tenant::create([
                'name' => $request->company_name,
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays),
                'max_stores' => $trialStores,
                'max_users' => $trialUsers,
            ]);

            $store = \App\Models\Store::create([
                'tenant_id' => $tenant->id,
                'name' => $request->store_name,
                'address' => $request->store_address,
                'phone' => $request->store_phone,
                'owner_id' => $user->id,
            ]);

            $user->update([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
            ]);

            return response()->json([
                'message' => 'Setup wizard completed successfully.',
                'user' => $user->fresh(['store', 'tenant']),
            ]);
        });
    }
}
