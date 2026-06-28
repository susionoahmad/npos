<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductCategoryFilterTest extends TestCase
{
    use DatabaseTransactions;

    protected $tenant;
    protected $store;
    protected $owner;
    protected $category1;
    protected $category2;
    protected $product1;
    protected $product2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Product Filter Tenant']);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Product Filter Store',
            'is_license_activated' => true,
        ]);

        $this->owner = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Filter Owner',
            'email' => 'owner_filt@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->category1 = Category::create([
            'store_id' => $this->store->id,
            'name' => 'Makanan',
        ]);

        $this->category2 = Category::create([
            'store_id' => $this->store->id,
            'name' => 'Minuman',
        ]);

        $this->product1 = Product::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category1->id,
            'name' => 'Nasi Goreng',
            'price' => 20000,
            'stock' => 10,
        ]);

        $this->product2 = Product::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category2->id,
            'name' => 'Es Teh Manis',
            'price' => 5000,
            'stock' => 20,
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
    public function it_filters_products_by_category_id()
    {
        $this->authenticate($this->owner);

        // Fetch without filter
        $response = $this->getJson('/api/products?per_page=100');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));

        // Filter category 1 (Makanan)
        $response1 = $this->getJson('/api/products?category_id=' . $this->category1->id);
        $response1->assertStatus(200);
        $data1 = $response1->json('data');
        $this->assertCount(1, $data1);
        $this->assertEquals('Nasi Goreng', $data1[0]['name']);

        // Filter category 2 (Minuman)
        $response2 = $this->getJson('/api/products?category_id=' . $this->category2->id);
        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        $this->assertCount(1, $data2);
        $this->assertEquals('Es Teh Manis', $data2[0]['name']);
    }
}
