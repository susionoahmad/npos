<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Category;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 0. Seed System Settings
        \App\Models\SystemSetting::insert([
            ['key' => 'subscription_trial_days', 'value' => '14', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'subscription_trial_stores_limit', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'subscription_trial_users_limit', 'value' => '3', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'subscription_base_fee', 'value' => '100000', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'subscription_store_addon_fee', 'value' => '50000', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'subscription_free_stores_limit', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 1. Create Tenant
        $tenant = Tenant::create([
            'name' => 'Nessa Group (SaaS Tenant)',
            'subscription_status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'subscription_ends_at' => now()->addDays(365),
            'max_stores' => 5,
            'max_users' => 20,
        ]);

        // Create Tenant Subscription Slots
        // Slot 1 (base)
        \App\Models\TenantSubscriptionSlot::create([
            'tenant_id' => $tenant->id,
            'slot_type' => 'base',
            'slot_index' => 1,
            'amount_paid' => 100000,
            'starts_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => 'active',
        ]);

        // Addon slots (max_stores is 5, so we create 4 addon slots)
        for ($i = 2; $i <= 5; $i++) {
            \App\Models\TenantSubscriptionSlot::create([
                'tenant_id' => $tenant->id,
                'slot_type' => 'addon',
                'slot_index' => $i,
                'amount_paid' => 50000,
                'starts_at' => now(),
                'expires_at' => now()->addDays(365),
                'status' => 'active',
            ]);
        }

        // 2. Create Stores under Tenant
        $storeMart = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Nessa Mart Demo',
            'address' => 'Jl. UMKM No. 1, Jakarta',
            'phone' => '081234567890',
            'currency' => 'IDR',
            'is_license_activated' => true,
            'license_activated_at' => now(),
        ]);

        $storeBakery = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Nessa Bakery & Cafe',
            'address' => 'Food Court Boulevard Space Lot 5',
            'phone' => '089988776655',
            'currency' => 'IDR',
            'is_license_activated' => true,
            'license_activated_at' => now(),
        ]);

        // 3. Create Users
        // SaaS Superadmin
        $superadmin = User::create([
            'name' => 'Superadmin Nessa',
            'email' => 'superadmin@nessapos.test',
            'password' => Hash::make('password123'),
            'role' => 'superadmin',
            'is_active' => true,
        ]);

        // Tenant Owner (assigned to Nessa Mart by default, but can switch)
        $owner = User::create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeMart->id,
            'name' => 'Owner Nessa Group',
            'email' => 'owner@nessapos.test',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $storeMart->update(['owner_id' => $owner->id]);
        $storeBakery->update(['owner_id' => $owner->id]);

        // Admin & Cashier for Nessa Mart
        User::create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeMart->id,
            'name' => 'Admin Nessa Mart',
            'email' => 'admin@nessapos.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeMart->id,
            'name' => 'Kasir Nessa Mart',
            'email' => 'kasir@nessapos.test',
            'password' => Hash::make('password123'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // Admin & Cashier for Nessa Bakery
        User::create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeBakery->id,
            'name' => 'Admin Bakery',
            'email' => 'admin.bakery@nessapos.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeBakery->id,
            'name' => 'Kasir Bakery',
            'email' => 'kasir.bakery@nessapos.test',
            'password' => Hash::make('password123'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // 4. Seed Categories and Products for Nessa Mart
        $catMartMinuman = Category::create([
            'store_id' => $storeMart->id,
            'name' => 'Minuman',
        ]);

        $catMartMakanan = Category::create([
            'store_id' => $storeMart->id,
            'name' => 'Makanan Cepat Saji',
        ]);

        $catMartSnack = Category::create([
            'store_id' => $storeMart->id,
            'name' => 'Snack / Cemilan',
        ]);

        Product::insert([
            [
                'store_id' => $storeMart->id,
                'category_id' => $catMartMinuman->id,
                'name' => 'Kopi Susu Gula Aren 250ml',
                'barcode' => '899100100001',
                'price' => 18000,
                'buying_price' => 11000,
                'stock' => 50,
                'image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'store_id' => $storeMart->id,
                'category_id' => $catMartMakanan->id,
                'name' => 'Mie Goreng Spesial',
                'barcode' => '899100100002',
                'price' => 15000,
                'buying_price' => 9000,
                'stock' => 30,
                'image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'store_id' => $storeMart->id,
                'category_id' => $catMartSnack->id,
                'name' => 'Keripik Singkong Balado',
                'barcode' => '899100100003',
                'price' => 10000,
                'buying_price' => 6000,
                'stock' => 100,
                'image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 5. Seed Categories and Products for Nessa Bakery & Cafe
        $catBakeryPastry = Category::create([
            'store_id' => $storeBakery->id,
            'name' => 'Pastry & Croissant',
        ]);

        $catBakeryCake = Category::create([
            'store_id' => $storeBakery->id,
            'name' => 'Cake & Tart',
        ]);

        $catBakeryCoffee = Category::create([
            'store_id' => $storeBakery->id,
            'name' => 'Kopi & Teh',
        ]);

        Product::insert([
            [
                'store_id' => $storeBakery->id,
                'category_id' => $catBakeryPastry->id,
                'name' => 'Butter Croissant',
                'barcode' => '899200200001',
                'price' => 22000,
                'buying_price' => 13000,
                'stock' => 40,
                'image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'store_id' => $storeBakery->id,
                'category_id' => $catBakeryCake->id,
                'name' => 'Red Velvet Slice Cake',
                'barcode' => '899200200002',
                'price' => 35000,
                'buying_price' => 20000,
                'stock' => 15,
                'image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'store_id' => $storeBakery->id,
                'category_id' => $catBakeryCoffee->id,
                'name' => 'Ice Cafe Latte Premium',
                'barcode' => '899200200003',
                'price' => 28000,
                'buying_price' => 16000,
                'stock' => 100,
                'image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
