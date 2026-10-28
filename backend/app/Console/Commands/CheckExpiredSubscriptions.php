<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSubscriptionSlot;
use Illuminate\Console\Command;

class CheckExpiredSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:check-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Memeriksa dan memperbarui status langganan tenant dan lisensi toko yang sudah kedaluwarsa';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = now();
        $this->info("Memulai pengecekan langganan kedaluwarsa pada: {$now->toDateTimeString()}");

        // 1. Cek Tenant aktif yang subscription_ends_at sudah lewat
        $expiredActiveTenants = Tenant::where('subscription_status', 'active')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', $now)
            ->get();

        $activeCount = 0;
        foreach ($expiredActiveTenants as $tenant) {
            $tenant->update(['subscription_status' => 'expired']);
            $tenant->stores()->update(['is_license_activated' => false]);
            $tenant->subscriptionSlots()
                ->where('status', 'active')
                ->where('expires_at', '<', $now)
                ->update(['status' => 'expired']);
            $activeCount++;
            $this->warn("Tenant [{$tenant->id}] {$tenant->name}: Status langganan kedaluwarsa (berakhir {$tenant->subscription_ends_at}). Lisensi toko dinonaktifkan.");
        }

        // 2. Cek Tenant trial yang trial_ends_at sudah lewat
        $expiredTrialTenants = Tenant::where('subscription_status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $now)
            ->get();

        $trialCount = 0;
        foreach ($expiredTrialTenants as $tenant) {
            $tenant->update(['subscription_status' => 'expired']);
            $tenant->stores()->update(['is_license_activated' => false]);
            $trialCount++;
            $this->warn("Tenant [{$tenant->id}] {$tenant->name}: Masa trial kedaluwarsa (berakhir {$tenant->trial_ends_at}). Lisensi toko dinonaktifkan.");
        }

        // 3. Cek slot langganan individu yang kedaluwarsa
        $expiredSlots = TenantSubscriptionSlot::where('status', 'active')
            ->where('expires_at', '<', $now)
            ->update(['status' => 'expired']);

        $this->info("Pengecekan selesai. {$activeCount} tenant aktif kedaluwarsa, {$trialCount} tenant trial kedaluwarsa, {$expiredSlots} slot langganan kedaluwarsa.");

        return Command::SUCCESS;
    }
}
