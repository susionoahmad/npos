<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Models\CashierCashMutation;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Transaction::with(['items.product', 'user', 'store'])
            ->whereIn('store_id', auth()->user()->getAccessibleStoreIds())
            ->when(request('from'), fn ($q) => $q->whereDate('created_at', '>=', request('from')))
            ->when(request('to'), fn ($q) => $q->whereDate('created_at', '<=', request('to')))
            ->when(request('search'), function ($q) {
                $search = request('search');
                $q->where(function ($sub) use ($search) {
                    $sub->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('payment_method', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($itemQuery) use ($search) {
                            $itemQuery->where('product_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->paginate(20);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $storeId = auth()->user()->store_id;
        $userId = auth()->id();

        // Verify active cashier session exists
        $activeSession = \App\Models\CashierSession::query()
            ->where('store_id', $storeId)
            ->where('user_id', $userId)
            ->where('status', 'AKTIF')
            ->first();

        if (!$activeSession) {
            throw new HttpResponseException(response()->json([
                'message' => 'Sesi kasir aktif belum dibuka. Silakan buka sesi kasir terlebih dahulu untuk memulai transaksi.',
            ], 422));
        }

        $trx = DB::transaction(function () use ($payload, $storeId, $activeSession) {
                $quantities = [];
                foreach ($payload['items'] as $line) {
                    $pid = (int) $line['product_id'];
                    $quantities[$pid] = ($quantities[$pid] ?? 0) + (int) $line['quantity'];
                }

                $items = [];
                $subTotal = 0;

                foreach ($quantities as $productId => $qty) {
                    $line = ['product_id' => $productId, 'quantity' => $qty];
                    $product = Product::query()
                        ->where('store_id', $storeId)
                        ->whereKey($productId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($line['quantity'] > $product->stock) {
                        throw new HttpResponseException(response()->json([
                            'message' => 'Stok tidak mencukupi untuk '.$product->name.' (tersedia: '.$product->stock.').',
                        ], 422));
                    }

                    $price = (float) $product->price;
                    $lineTotal = round($price * $line['quantity'], 2);
                    $subTotal = round($subTotal + $lineTotal, 2);
                    $items[] = ['product' => $product, 'quantity' => $line['quantity'], 'lineTotal' => $lineTotal];
                }

                $discount = 0.0;
                if (($payload['discount_type'] ?? null) === 'percent') {
                    $discount = round($subTotal * ((float) ($payload['discount_value'] ?? 0) / 100), 2);
                } elseif (($payload['discount_type'] ?? null) === 'fixed') {
                    $discount = round((float) ($payload['discount_value'] ?? 0), 2);
                }
                $discount = min($discount, $subTotal);

                $taxBase = max(0, round($subTotal - $discount, 2));
                $tax = round($taxBase * ((float) ($payload['tax_percent'] ?? 0) / 100), 2);
                $total = max(0, round($taxBase + $tax, 2));

                if ($payload['payment_method'] === 'cash') {
                    $total = round($total / 100) * 100;
                }

                $paid = round((float) $payload['paid_amount'], 2);
                if ($paid < $total) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Jumlah bayar harus minimal total transaksi.',
                    ], 422));
                }

                $trx = Transaction::create([
                    'store_id' => $storeId,
                    'user_id' => auth()->id(),
                    'cashier_session_id' => $activeSession->id,
                    'invoice_number' => 'INV-'.now()->format('YmdHis').'-'.random_int(100, 999),
                    'sub_total' => $subTotal,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                    'total' => $total,
                    'paid_amount' => $paid,
                    'change_amount' => round($paid - $total, 2),
                    'payment_method' => $payload['payment_method'],
                ]);

                foreach ($items as $line) {
                    $p = $line['product'];
                    $trx->items()->create([
                        'product_id'   => $p->id,
                        'product_name' => $p->name,
                        'price'        => $p->price,
                        'buying_price' => $p->buying_price,
                        'quantity'     => $line['quantity'],
                        'line_total'   => $line['lineTotal'],
                    ]);
                    $p->decrement('stock', $line['quantity']);

                    \App\Models\StockMutation::create([
                        'store_id'   => $storeId,
                        'product_id' => $p->id,
                        'user_id'    => auth()->id(),
                        'type'       => 'out',
                        'quantity'   => $line['quantity'],
                        'reference'  => $trx->invoice_number,
                        'notes'      => 'Penjualan POS',
                    ]);
                }

                // Auto-record transaksi TUNAI ke Mutasi Kas Kasir (rekening koran laci)
                if ($payload['payment_method'] === 'cash') {
                    $dateStr   = Carbon::now()->format('Ymd');
                    $mutCount  = CashierCashMutation::whereDate('created_at', Carbon::today())->count();
                    $mutNumber = 'MUT-' . $dateStr . '-' . str_pad($mutCount + 1, 4, '0', STR_PAD_LEFT);

                    CashierCashMutation::create([
                        'store_id'           => $storeId,
                        'user_id'            => auth()->id(),
                        'cashier_session_id' => $activeSession->id,
                        'mutation_number'    => $mutNumber,
                        'type'               => 'penjualan_tunai',
                        'direction'          => 'in',
                        'amount'             => $total,
                        'notes'              => 'Penjualan tunai ' . $trx->invoice_number,
                        'reference_number'   => $trx->invoice_number,
                    ]);
                }

                return $trx->load(['items', 'store', 'user']);
        });

        return response()->json([
            'message' => 'Transaksi berhasil.',
            'transaction' => $trx,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Transaction $transaction)
    {
        abort_unless(in_array($transaction->store_id, auth()->user()->getAccessibleStoreIds()), 404);

        return $transaction->load(['items', 'store', 'user']);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(\Illuminate\Http\Request $request, string $id)
    {
        return response()->json(['message' => 'Not implemented']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        return response()->json(['message' => 'Not implemented']);
    }
}
