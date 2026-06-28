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
        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10); // 'in' or 'out'
            $table->integer('quantity');
            $table->string('reference', 100);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        // Seed historical cashier sales as 'out' mutations
        DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->select(
                'transactions.store_id',
                'transaction_items.product_id',
                'transactions.user_id',
                'transaction_items.quantity',
                'transactions.invoice_number as reference',
                'transactions.created_at'
            )
            ->orderBy('transaction_items.id')
            ->chunk(100, function ($items) {
                $mutations = [];
                foreach ($items as $item) {
                    $mutations[] = [
                        'store_id' => $item->store_id,
                        'product_id' => $item->product_id,
                        'user_id' => $item->user_id,
                        'type' => 'out',
                        'quantity' => $item->quantity,
                        'reference' => $item->reference,
                        'notes' => 'Penjualan POS',
                        'created_at' => $item->created_at,
                        'updated_at' => $item->created_at,
                    ];
                }
                DB::table('stock_mutations')->insert($mutations);
            });

        // Seed historical supplier purchases as 'in' mutations
        DB::table('purchase_items')
            ->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
            ->select(
                'purchases.store_id',
                'purchase_items.product_id',
                'purchases.user_id',
                'purchase_items.quantity',
                'purchases.purchase_number as reference',
                'purchases.notes',
                'purchase_items.created_at'
            )
            ->orderBy('purchase_items.id')
            ->chunk(100, function ($items) {
                $mutations = [];
                foreach ($items as $item) {
                    $mutations[] = [
                        'store_id' => $item->store_id,
                        'product_id' => $item->product_id,
                        'user_id' => $item->user_id,
                        'type' => 'in',
                        'quantity' => $item->quantity,
                        'reference' => $item->reference,
                        'notes' => $item->notes ?? 'Penerimaan Supplier',
                        'created_at' => $item->created_at,
                        'updated_at' => $item->created_at,
                    ];
                }
                DB::table('stock_mutations')->insert($mutations);
            });

        // Seed initial stock for existing products as 'in' mutations (so the current stock balance traces correctly)
        DB::table('products')
            ->where('stock', '>', 0)
            ->select('id', 'store_id', 'stock', 'created_at')
            ->orderBy('id')
            ->chunk(100, function ($products) {
                $mutations = [];
                foreach ($products as $p) {
                    $mutations[] = [
                        'store_id' => $p->store_id,
                        'product_id' => $p->id,
                        'user_id' => null,
                        'type' => 'in',
                        'quantity' => $p->stock,
                        'reference' => 'Stok Awal',
                        'notes' => 'Saldo awal produk saat dibuat',
                        'created_at' => $p->created_at,
                        'updated_at' => $p->created_at,
                    ];
                }
                DB::table('stock_mutations')->insert($mutations);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_mutations');
    }
};
