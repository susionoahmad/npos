<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 50)->unique();
                $table->string('name', 100);
                $table->string('tagline')->nullable();
                $table->unsignedBigInteger('price_monthly')->default(0);
                $table->unsignedBigInteger('price_yearly')->default(0);
                $table->integer('max_stores')->default(1);
                $table->integer('max_users')->default(3);
                $table->json('features')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'plan')) {
                $table->string('plan', 50)->default('starter')->after('name');
            }
            if (!Schema::hasColumn('tenants', 'billing_cycle')) {
                $table->string('billing_cycle', 20)->default('monthly')->after('plan');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['plan', 'billing_cycle']);
        });

        Schema::dropIfExists('subscription_plans');
    }
};