<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function new_owner_can_register_via_api()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Budi Owner Baru',
            'email' => 'budibaru@nessapos.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'token',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'tenant_id',
                    'store_id',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'budibaru@nessapos.test',
            'role' => 'owner',
            'tenant_id' => null,
            'store_id' => null,
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
    public function owner_without_tenant_can_complete_setup_wizard()
    {
        $user = User::create([
            'name' => 'Setup Owner',
            'email' => 'setupowner@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->authenticate($user);

        $response = $this->postJson('/api/setup-wizard', [
            'company_name' => 'Budi Bakery Group',
            'store_name' => 'Budi Bakery - Cabang 1',
            'store_address' => 'Jl. Sudirman No. 15',
            'store_phone' => '021-998877',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'user' => [
                    'id',
                    'tenant_id',
                    'store_id',
                ],
            ]);

        $user = $user->fresh();
        $this->assertNotNull($user->tenant_id);
        $this->assertNotNull($user->store_id);

        $this->assertDatabaseHas('tenants', [
            'id' => $user->tenant_id,
            'name' => 'Budi Bakery Group',
        ]);

        $this->assertDatabaseHas('stores', [
            'id' => $user->store_id,
            'tenant_id' => $user->tenant_id,
            'name' => 'Budi Bakery - Cabang 1',
            'address' => 'Jl. Sudirman No. 15',
            'phone' => '021-998877',
            'owner_id' => $user->id,
        ]);
    }

    /** @test */
    public function owner_with_existing_tenant_cannot_run_setup_wizard_again()
    {
        $tenant = \App\Models\Tenant::create(['name' => 'Existing Company']);
        $store = \App\Models\Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Existing Store',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'name' => 'Setup Owner',
            'email' => 'setupowner@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->authenticate($user);

        $response = $this->postJson('/api/setup-wizard', [
            'company_name' => 'New Company Name',
            'store_name' => 'New Store Name',
        ]);

        $response->assertStatus(400);
    }
}
