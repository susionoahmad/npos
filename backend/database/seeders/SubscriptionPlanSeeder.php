<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'starter',
                'name' => 'Starter POS',
                'tagline' => 'Solusi kasir lengkap untuk 1 toko atau UMKM mandiri.',
                'price_monthly' => 99000,
                'price_yearly' => 990000,
                'max_stores' => 1,
                'max_users' => 3,
                'features' => [
                    '1 Cabang Toko',
                    '3 Pengguna Sistem (Kasir / Admin)',
                    'Modul Kasir POS & Transaksi',
                    'Manajemen Inventori & Produk',
                    'Laporan Penjualan & Keuangan Lengkap',
                    'Sistem Kasir Buka/Tutup Shift',
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro POS (Multi-Cabang)',
                'tagline' => 'Terbaik untuk bisnis ritel berkembang dengan beberapa cabang toko.',
                'price_monthly' => 249000,
                'price_yearly' => 2490000,
                'max_stores' => 3,
                'max_users' => 15,
                'features' => [
                    'Hingga 3 Cabang Toko Terlisensi',
                    '15 Pengguna Sistem Terdistribusi',
                    'Pindah Cabang Cepat untuk Owner (Store Switching)',
                    'Laporan Konsolidasi & Laba Rugi per Cabang',
                    'Modul Kas Besar & Kasir Shift',
                    'Prioritas Dukungan Teknis',
                ],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise Retail',
                'tagline' => 'Kapasitas maksimal untuk jaringan waralaba dan ritel besar.',
                'price_monthly' => 599000,
                'price_yearly' => 5990000,
                'max_stores' => 20,
                'max_users' => 100,
                'features' => [
                    'Hingga 20 Cabang Toko Terlisensi',
                    'Hingga 100 Pengguna Sistem',
                    'Manajemen Multi-Tenant & Multi-Store Penuh',
                    'Laporan Mutasi Stok Antar Cabang',
                    'Kustomisasi Fitur & Integrasi Khusus',
                    'Dedicated Server Support & SLA 99.9%',
                ],
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $planData) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }

        $this->command->info('Subscription plans seeded / updated successfully.');
    }
}