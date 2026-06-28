<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReportStockMutationsTest extends TestCase
{
    use DatabaseTransactions;

    protected $tenant;
    protected $store;
    protected $owner;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Mutation Test Tenant']);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Mutation Test Store',
            'is_license_activated' => true,
        ]);

        $this->owner = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Mutation Owner',
            'email' => 'owner_mut@nessapos.test',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'store_id' => $this->store->id,
            'name' => 'Kopi Latte',
            'price' => 15000,
            'buying_price' => 10000,
            'stock' => 50,
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
    public function owner_can_get_stock_mutations_report()
    {
        $this->authenticate($this->owner);

        // 1. Create a Sale (Barang Keluar)
        $transaction = Transaction::create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
            'invoice_number' => 'INV-001',
            'sub_total' => 15000,
            'total' => 15000,
            'paid_amount' => 20000,
            'change_amount' => 5000,
            'payment_method' => 'cash',
        ]);
        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 15000,
            'buying_price' => 10000,
            'quantity' => 2,
            'line_total' => 30000,
        ]);
        \App\Models\StockMutation::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'user_id' => $this->owner->id,
            'type' => 'out',
            'quantity' => 2,
            'reference' => 'INV-001',
            'notes' => 'Penjualan POS',
        ]);

        // 2. Create a Purchase (Barang Masuk)
        $purchase = Purchase::create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
            'purchase_number' => 'PR-001',
            'purchase_date' => now()->toDateString(),
            'sub_total' => 100000,
            'total' => 100000,
            'paid_amount' => 100000,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 10,
            'buying_price' => 10000,
            'line_total' => 100000,
        ]);
        \App\Models\StockMutation::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'user_id' => $this->owner->id,
            'type' => 'in',
            'quantity' => 10,
            'reference' => 'PR-001',
            'notes' => 'Penerimaan Supplier',
        ]);

        // 3. Request report
        $response = $this->getJson('/api/reports/stock-mutations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'rows' => [
                    '*' => [
                        'type',
                        'product_id',
                        'product_name',
                        'quantity',
                        'reference',
                        'date',
                        'user_name',
                        'notes',
                    ]
                ],
                'from',
                'to',
            ]);

        $rows = $response->json('rows');
        $this->assertCount(2, $rows);

        // First row should be the most recent (depends on timestamps, let's assert presence of both type in and out)
        $types = collect($rows)->pluck('type');
        $this->assertTrue($types->contains('in'));
        $this->assertTrue($types->contains('out'));

        $quantities = collect($rows)->pluck('quantity');
        $this->assertTrue($quantities->contains(2));
        $this->assertTrue($quantities->contains(10));
    }
}
