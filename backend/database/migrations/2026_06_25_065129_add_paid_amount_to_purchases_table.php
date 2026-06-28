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
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 2)->default(0)->after('total');
        });

        // Populate paid_amount for existing records
        DB::table('purchases')->whereIn('payment_method', ['cash', 'transfer'])->update([
            'paid_amount' => DB::raw('total'),
            'payment_status' => 'PAID',
        ]);

        DB::table('purchases')->where('payment_method', 'debt')->where('payment_status', 'PAID')->update([
            'paid_amount' => DB::raw('total'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
