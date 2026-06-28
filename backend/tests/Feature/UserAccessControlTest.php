<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UserAccessControlTest extends TestCase
{
    use DatabaseTransactions;

    protected $tenant;
    protected $store1;
    protected $store2;
    protected $owner;
    protected $admin1;
    protected $admin2;
    protected $cashier1;
    protected $cashier2;
    protected $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a Tenant
        $this->tenant = Tenant::create(['name' => 'Test Tenant']);

        // Create Stores
        $this->store1 = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store 1',
            'currency' => 'IDR',
            'is_license_activated' => true,
        ]);
        $this->store2 = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store 2',
            'currency' => 'IDR',
            'is_license_activated' => true,
        ]);

        // Create Users in Store 1
        $this->owner = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'name' => 'Tenant Owner',
            'email' => 'owner_test@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->admin1 = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'name' => 'Admin 1',
            'email' => 'admin1_test@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->cashier1 = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store1->id,
            'name' => 'Cashier 1',
            'email' => 'cashier1_test@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // Create Users in Store 2
        $this->admin2 = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store2->id,
            'name' => 'Admin 2',
            'email' => 'admin2_test@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->cashier2 = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store2->id,
            'name' => 'Cashier 2',
            'email' => 'cashier2_test@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // Other tenant owner for safety check
        $otherTenant = Tenant::create(['name' => 'Other Tenant']);
        $otherStore = Store::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Store',
            'currency' => 'IDR',
            'is_license_activated' => true,
        ]);
        $this->otherOwner = User::create([
            'tenant_id' => $otherTenant->id,
            'store_id' => $otherStore->id,
            'name' => 'Other Tenant Owner',
            'email' => 'other_owner_test@nessapos.test',
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

    public function test_admin_can_only_list_self_and_own_store_cashiers()
    {
        $this->authenticate($this->admin1);

        $response = $this->getJson('/api/users');
        $response->assertStatus(200);

        $data = $response->json();
        $ids = collect($data)->pluck('id');

        // Should include self and cashier 1 (same store)
        $this->assertTrue($ids->contains($this->admin1->id));
        $this->assertTrue($ids->contains($this->cashier1->id));

        // Should NOT include owner, other admin, or other cashier
        $this->assertFalse($ids->contains($this->owner->id));
        $this->assertFalse($ids->contains($this->admin2->id));
        $this->assertFalse($ids->contains($this->cashier2->id));
    }

    public function test_admin_can_only_create_cashiers_in_own_store()
    {
        $this->authenticate($this->admin1);

        // Attempt to create owner should fail (403)
        $response = $this->postJson('/api/users', [
            'name' => 'New Owner Attempt',
            'email' => 'new_owner@test.com',
            'password' => 'password123',
            'role' => 'owner',
        ]);
        $response->assertStatus(403);

        // Attempt to create admin should fail (403)
        $response = $this->postJson('/api/users', [
            'name' => 'New Admin Attempt',
            'email' => 'new_admin@test.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);
        $response->assertStatus(403);

        // Creating cashier should succeed and be forced to the admin's store and tenant
        $response = $this->postJson('/api/users', [
            'name' => 'New Cashier',
            'email' => 'new_cashier@test.com',
            'password' => 'password123',
            'role' => 'cashier',
        ]);
        $response->assertStatus(201);
        $this->assertEquals($this->admin1->store_id, $response->json('store_id'));
        $this->assertEquals($this->admin1->tenant_id, $response->json('tenant_id'));
    }

    public function test_admin_can_only_show_self_and_own_store_cashiers()
    {
        $this->authenticate($this->admin1);

        // Can show self
        $this->getJson("/api/users/{$this->admin1->id}")->assertStatus(200);

        // Can show own store cashier
        $this->getJson("/api/users/{$this->cashier1->id}")->assertStatus(200);

        // Cannot show owner (returns 404 since it's filtered from query before findOrFail)
        $this->getJson("/api/users/{$this->owner->id}")->assertStatus(404);

        // Cannot show other admin
        $this->getJson("/api/users/{$this->admin2->id}")->assertStatus(404);
    }

    public function test_admin_can_update_self_and_own_store_cashiers_with_restrictions()
    {
        $this->authenticate($this->admin1);

        // Can update own profile details, but role cannot be changed (ignored/unset)
        $response = $this->putJson("/api/users/{$this->admin1->id}", [
            'name' => 'Admin 1 Updated',
            'role' => 'owner', // Attempt to escalate role
        ]);
        $response->assertStatus(200);
        $this->assertEquals('Admin 1 Updated', $response->json('name'));
        $this->assertEquals('admin', $response->json('role')); // Role remains admin

        // Can update cashier in own store
        $response = $this->putJson("/api/users/{$this->cashier1->id}", [
            'name' => 'Cashier 1 Updated',
        ]);
        $response->assertStatus(200);

        // Cannot escalate cashier to owner (403)
        $response = $this->putJson("/api/users/{$this->cashier1->id}", [
            'role' => 'owner',
        ]);
        $response->assertStatus(403);

        // Cannot update owner
        $this->putJson("/api/users/{$this->owner->id}", [
            'name' => 'Owner Attempted Update',
        ])->assertStatus(403);

        // Cannot update other admin
        $this->putJson("/api/users/{$this->admin2->id}", [
            'name' => 'Admin 2 Attempted Update',
        ])->assertStatus(403);
    }

    public function test_admin_can_only_delete_self_and_own_store_cashiers()
    {
        $this->authenticate($this->admin1);

        // Cannot delete owner
        $this->deleteJson("/api/users/{$this->owner->id}")->assertStatus(403);

        // Cannot delete other admin
        $this->deleteJson("/api/users/{$this->admin2->id}")->assertStatus(403);
        // Can delete own store cashier
        $this->deleteJson("/api/users/{$this->cashier1->id}")->assertStatus(200);
    }

    public function test_owner_filtering_by_store_and_consolidation()
    {
        // Log in as Owner
        $this->authenticate($this->owner);

        // 1. When owner has store1 selected, they should see only users from store 1
        $this->owner->update(['store_id' => $this->store1->id]);
        $response = $this->getJson("/api/users");
        $response->assertStatus(200);
        $userIds = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($this->admin1->id, $userIds);
        $this->assertContains($this->cashier1->id, $userIds);
        $this->assertNotContains($this->admin2->id, $userIds);
        $this->assertNotContains($this->cashier2->id, $userIds);
        // 2. When owner has consolidation mode selected (store_id = null), they should see all users under their tenant
        $this->owner->update(['store_id' => null]);
        $fresh = $this->owner->fresh();
        auth()->setUser($fresh);
        auth()->guard('sanctum')->setUser($fresh);
        
        $response = $this->getJson("/api/users");
        $response->assertStatus(200);
        $userIds = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($FreshUser = $this->admin1->id, $userIds);
        $this->assertContains($this->cashier1->id, $userIds);
        $this->assertContains($this->admin2->id, $userIds);
        $this->assertContains($this->cashier2->id, $userIds);
    }
}
