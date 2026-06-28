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
        Schema::create('cashier_cash_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_session_id')->constrained()->cascadeOnDelete();
            $table->string('mutation_number')->unique();
            $table->string('type'); // tambah, kurang, koreksi, pengeluaran
            $table->string('direction'); // in, out
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->string('reference_number')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
            $table->index(['cashier_session_id', 'direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashier_cash_mutations');
    }
};
