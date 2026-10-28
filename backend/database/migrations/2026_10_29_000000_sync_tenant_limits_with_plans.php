<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sinkronkan batas toko dan user tenant yang masih menggunakan nilai slot lama
        $plans = DB::table('subscription_plans')->get();

        foreach ($plans as $plan) {
            DB::table('tenants')
                ->where('plan', $plan->slug)
                ->update([
                    'max_stores' => $plan->max_stores,
                    'max_users'  => $plan->max_users,
                ]);
        }

        // Untuk tenant yang plan-nya null atau belum diset, default ke starter
        $starterPlan = DB::table('subscription_plans')->where('slug', 'starter')->first();
        if ($starterPlan) {
            DB::table('tenants')
                ->whereNull('plan')
                ->orWhere('plan', '')
                ->update([
                    'plan'       => 'starter',
                    'max_stores' => $starterPlan->max_stores,
                    'max_users'  => $starterPlan->max_users,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback needed
    }
};