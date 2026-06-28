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
        Schema::create('general_cash_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // user yang mencatat
            $table->string('reference_number')->unique();                  // No. bukti
            $table->string('type');                                         // modal_awal_kasir, setoran_kasir, pengeluaran_operasional, pembelian_barang, bayar_supplier, transfer_bank, penyetoran_bank, penarikan_operasional, koreksi
            $table->string('direction');                                    // in, out
            $table->decimal('amount', 14, 2);
            $table->string('source')->nullable();                           // sumber dana (e.g. "Kasir Pagi", "Rekening BCA")
            $table->string('destination')->nullable();                      // tujuan dana
            $table->text('notes')->nullable();
            // Optional link to cashier session for setoran/modal awal tracking
            $table->foreignId('cashier_session_id')->nullable()->constrained()->nullOnDelete();
            // Optional approver
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
            $table->index(['store_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('general_cash_mutations');
    }
};
