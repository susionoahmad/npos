<?php
// Script simulasi manual webhook Midtrans untuk testing lokal
// Jalankan: php simulate_webhook.php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

// Tampilkan semua tenant
$tenants = Tenant::all();
echo "=== DAFTAR SEMUA TENANT ===\n";
foreach ($tenants as $t) {
    echo "ID: {$t->id} | {$t->name} | Status: {$t->subscription_status} | MaxStore: {$t->max_stores}\n";
}
echo "\n";

// Update semua tenant yang masih TRIAL menjadi ACTIVE
$trialTenants = Tenant::where('subscription_status', 'trial')->get();
if ($trialTenants->isEmpty()) {
    echo "ℹ️  Tidak ada tenant berstatus TRIAL.\n";
} else {
    foreach ($trialTenants as $tenant) {
        $newEnd = now()->addDays(30);
        $tenant->update([
            'subscription_status' => 'active',
            'subscription_ends_at' => $newEnd,
            'max_stores' => 1,
            'max_users' => 100,
        ]);
        echo "✅ Tenant '{$tenant->name}' (ID:{$tenant->id}) → ACTIVE hingga {$newEnd->toDateString()}\n";
    }
}

