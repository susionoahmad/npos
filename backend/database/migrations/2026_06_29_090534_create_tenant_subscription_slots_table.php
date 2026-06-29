<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscription_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('slot_type', ['base', 'addon'])->default('base');
            $table->unsignedSmallInteger('slot_index')->default(1); // 1=base, 2,3,4...=addon
            $table->unsignedInteger('amount_paid')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['active', 'expired', 'pending'])->default('pending');
            $table->string('midtrans_order_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slot_index']);
            $table->index(['tenant_id', 'status']);
            $table->index(['expires_at', 'status']);
        });

        // Migrate existing active tenants to slots
        $tenants = \DB::table('tenants')->where('subscription_status', 'active')->get();
        foreach ($tenants as $tenant) {
            $endsAt = $tenant->subscription_ends_at ?? now()->addDays(30);
            // Slot 1 (base)
            \DB::table('tenant_subscription_slots')->insert([
                'tenant_id' => $tenant->id,
                'slot_type' => 'base',
                'slot_index' => 1,
                'amount_paid' => 100000,
                'starts_at' => now(),
                'expires_at' => $endsAt,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Addon slots
            $maxStores = (int) ($tenant->max_stores ?? 1);
            for ($i = 2; $i <= $maxStores; $i++) {
                \DB::table('tenant_subscription_slots')->insert([
                    'tenant_id' => $tenant->id,
                    'slot_type' => 'addon',
                    'slot_index' => $i,
                    'amount_paid' => 50000,
                    'starts_at' => now(),
                    'expires_at' => $endsAt,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscription_slots');
    }
};
