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
        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->decimal('expenses_amount', 14, 2)->default(0)->after('start_balance');
            $table->decimal('deposit_amount', 14, 2)->default(0)->after('expenses_amount');
            $table->decimal('expected_balance', 14, 2)->default(0)->after('deposit_amount');
            $table->decimal('difference_amount', 14, 2)->default(0)->after('end_balance');
            $table->text('difference_reason')->nullable()->after('difference_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'expenses_amount',
                'deposit_amount',
                'expected_balance',
                'difference_amount',
                'difference_reason',
            ]);
        });
    }
};
