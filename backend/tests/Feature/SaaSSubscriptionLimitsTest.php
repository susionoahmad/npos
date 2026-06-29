<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Store;
use App\Models\User;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SaaSSubscriptionLimitsTest extends TestCase
{
    use DatabaseTransactions;

    protected $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed default SaaS system settings
        SystemSetting::setVal('subscription_trial_days', 14);
        SystemSetting::setVal('subscription_trial_stores_limit', 1);
        SystemSetting::setVal('subscription_trial_users_limit', 3);
        SystemSetting::setVal('subscription_base_fee', 100000);
        SystemSetting::setVal('subscription_store_addon_fee', 50000);
        SystemSetting::setVal('subscription_free_stores_limit', 1);

        // Create initial owner without tenant yet to test wizard
        $this->owner = User::create([
            'name' => 'Trial Owner',
            'email' => 'trial_owner@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    protected function authenticate(User $user)
    {
        $plainToken = $user->createToken('test-token')->plainTextToken;
        $token = explode('|', $plainToken)[1] ?? $plainToken;
        $user->update(['active_session_token' => hash('sha256', $token)]);
        $this->withHeader('Authorization', 'Bearer ' . $plainToken);
    }

    /** @test */
    public function setup_wizard_sets_trial_limits_correctly()
    {
        $this->authenticate($this->owner);

        $response = $this->postJson('/api/setup-wizard', [
            'company_name' => 'Trial Retail Group',
            'store_name' => 'Trial Store 1',
            'store_address' => 'Jl. Trial No. 1',
            'store_phone' => '081234567890',
        ]);

        $response->assertStatus(200);
        $tenant = Tenant::latest()->first();

        $this->assertEquals('trial', $tenant->subscription_status);
        $this->assertEquals(1, $tenant->max_stores);
        $this->assertEquals(3, $tenant->max_users);
        $this->assertTrue(now()->addDays(14)->isSameDay($tenant->trial_ends_at));
    }

    /** @test */
    public function enforces_maximum_store_limit_during_trial()
    {
        $this->authenticate($this->owner);

        // Run setup wizard to create trial tenant
        $this->postJson('/api/setup-wizard', [
            'company_name' => 'Trial Retail Group',
            'store_name' => 'Trial Store 1',
        ]);

        // Attempt to create second store (should fail with 403)
        $response = $this->postJson('/api/tenant/stores', [
            'name' => 'Trial Store 2',
            'currency' => 'IDR',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Batas maksimal cabang toko terlampaui. Silakan perbarui langganan Anda.'
        ]);
    }

    /** @test */
    public function enforces_maximum_user_limit_during_trial()
    {
        $this->authenticate($this->owner);

        // Run setup wizard to create trial tenant and first store
        $this->postJson('/api/setup-wizard', [
            'company_name' => 'Trial Retail Group',
            'store_name' => 'Trial Store 1',
        ]);

        // Create 2nd user (owner is 1st)
        $this->postJson('/api/users', [
            'name' => 'Admin 1',
            'email' => 'admin1@trial.com',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertStatus(201);

        // Create 3rd user
        $this->postJson('/api/users', [
            'name' => 'Kasir 1',
            'email' => 'kasir1@trial.com',
            'password' => 'password123',
            'role' => 'cashier',
        ])->assertStatus(201);

        // Attempt to create 4th user (should fail with 403)
        $response = $this->postJson('/api/users', [
            'name' => 'Kasir 2',
            'email' => 'kasir2@trial.com',
            'password' => 'password123',
            'role' => 'cashier',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Batas maksimal pengguna terlampaui. Silakan perbarui langganan Anda.'
        ]);
    }

    /** @test */
    public function midtrans_payment_callback_activates_subscription_and_extends_limits()
    {
        $this->authenticate($this->owner);

        // Run setup wizard
        $this->postJson('/api/setup-wizard', [
            'company_name' => 'Trial Retail Group',
            'store_name' => 'Trial Store 1',
        ]);

        $tenant = Tenant::latest()->first();

        // 1. Emulate Webhook for Base Slot (index 1)
        $orderId = 'SUB_' . $tenant->id . '_ADDON_1_' . time();
        $serverKey = config('services.midtrans.server_key') ?: 'SB-Mid-server-lh-48s0YtD3tQ30w0b-W6vG8';
        $statusCode = '200';
        $grossAmount = '100000'; // base fee
        $signatureKey = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        $response = $this->postJson('/api/payments/midtrans/callback', [
            'order_id' => $orderId,
            'transaction_status' => 'settlement',
            'statusCode' => $statusCode,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
        ]);

        $response->assertStatus(200);

        // 2. Emulate Webhook for Addon Slot (index 2)
        $orderId2 = 'SUB_' . $tenant->id . '_ADDON_2_' . time();
        $grossAmount2 = '50000'; // addon fee
        $signatureKey2 = hash('sha512', $orderId2 . $statusCode . $grossAmount2 . $serverKey);

        $response2 = $this->postJson('/api/payments/midtrans/callback', [
            'order_id' => $orderId2,
            'transaction_status' => 'settlement',
            'statusCode' => $statusCode,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount2,
            'signature_key' => $signatureKey2,
        ]);

        $response2->assertStatus(200);

        $tenant->refresh();
        $this->assertEquals('active', $tenant->subscription_status);
        $this->assertEquals(2, $tenant->activeSlotStoreLimit());
        $this->assertEquals(100, $tenant->max_users);

        // Now creating a 2nd store should succeed!
        $this->postJson('/api/tenant/stores', [
            'name' => 'Active Store 2',
            'currency' => 'IDR',
        ])->assertStatus(201);
    }
}
