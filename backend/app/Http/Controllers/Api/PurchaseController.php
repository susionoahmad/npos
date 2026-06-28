<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\GeneralCashMutation;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PurchaseController extends Controller
{
    /**
     * Display a listing of purchases.
     */
    public function index(Request $request)
    {
        $query = Purchase::with(['supplier', 'user', 'items.product'])
            ->whereIn('store_id', auth()->user()->getAccessibleStoreIds());

        // Filters
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
        if ($request->filled('from')) {
            $query->whereDate('purchase_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('purchase_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('purchase_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('items', function ($itemQ) use ($search) {
                      $itemQ->where('product_name', 'like', "%{$search}%");
                  });
            });
        }

        return $query->latest('purchase_date')
            ->latest('id')
            ->paginate(20);
    }

    /**
     * Store a newly created purchase in storage.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $storeId = $user->store_id;
        if ($user->role === 'owner' && !$storeId) {
            return response()->json(['message' => 'Please select a specific store first to record a purchase.'], 400);
        }
        abort_unless($storeId, 422, 'Pengguna belum terhubung dengan toko.');

        $payload = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'purchase_date' => ['required', 'date'],
            'payment_method' => ['required', 'string', 'in:cash,transfer,debt'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.buying_price' => ['required', 'numeric', 'min:0'],
        ]);

        $purchase = DB::transaction(function () use ($payload, $storeId, $user) {
            $subTotal = 0;
            $itemsData = [];

            foreach ($payload['items'] as $item) {
                $product = Product::where('store_id', $storeId)
                    ->findOrFail($item['product_id']);

                $lineTotal = $item['quantity'] * $item['buying_price'];
                $subTotal += $lineTotal;

                $itemsData[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'buying_price' => $item['buying_price'],
                    'line_total' => $lineTotal,
                ];
            }

            $discount = (float) ($payload['discount_amount'] ?? 0);
            $tax = (float) ($payload['tax_amount'] ?? 0);
            $total = max(0, $subTotal - $discount + $tax);

            // Generate Purchase Number
            $dateStr = Carbon::parse($payload['purchase_date'])->format('Ymd');
            $todayCount = Purchase::whereDate('created_at', Carbon::today())->count();
            $purchaseNumber = 'PRCH-' . $dateStr . '-' . str_pad($todayCount + 1, 4, '0', STR_PAD_LEFT);

            $paymentStatus = $payload['payment_method'] === 'debt' ? 'PENDING' : 'PAID';
            $paidAmount = $payload['payment_method'] === 'debt' ? 0 : $total;

            // Create Purchase record
            $purchase = Purchase::create([
                'store_id' => $storeId,
                'supplier_id' => $payload['supplier_id'] ?? null,
                'user_id' => $user->id,
                'purchase_number' => $purchaseNumber,
                'purchase_date' => $payload['purchase_date'],
                'sub_total' => $subTotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'payment_status' => $paymentStatus,
                'payment_method' => $payload['payment_method'],
                'notes' => $payload['notes'] ?? null,
            ]);

            // Save items & update product stock & buying price
            foreach ($itemsData as $data) {
                $product = $data['product'];
                
                $purchase->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $data['quantity'],
                    'buying_price' => $data['buying_price'],
                    'line_total' => $data['line_total'],
                ]);

                // Increment stock
                $product->increment('stock', $data['quantity']);

                \App\Models\StockMutation::create([
                    'store_id'   => $storeId,
                    'product_id' => $product->id,
                    'user_id'    => $user->id,
                    'type'       => 'in',
                    'quantity'   => $data['quantity'],
                    'reference'  => $purchaseNumber,
                    'notes'      => $payload['notes'] ?? 'Penerimaan Supplier',
                ]);
                
                // Update product buying price to the latest purchase cost
                $product->update([
                    'buying_price' => $data['buying_price']
                ]);
            }

            // Auto-record to General Cash Mutation (Kas Besar) if paid immediately (cash / transfer)
            if (in_array($payload['payment_method'], ['cash', 'transfer'])) {
                $mutCount = GeneralCashMutation::whereDate('created_at', Carbon::today())->count();
                $refNumber = 'KBS-' . Carbon::now()->format('Ymd') . '-' . str_pad($mutCount + 1, 4, '0', STR_PAD_LEFT);
                
                $supplierName = $purchase->supplier?->name ?? 'Supplier';

                GeneralCashMutation::create([
                    'store_id' => $storeId,
                    'user_id' => $user->id,
                    'reference_number' => $refNumber,
                    'type' => 'pembelian_barang',
                    'direction' => 'out',
                    'amount' => $total,
                    'source' => 'Kas Besar Toko',
                    'destination' => $supplierName,
                    'notes' => 'Pembelian produk ' . $purchase->purchase_number . ' dari ' . $supplierName,
                ]);
            }

            return $purchase;
        });

        return response()->json([
            'message' => 'Pembelian produk berhasil dicatat dan stok telah diperbarui.',
            'purchase' => $purchase->load(['items', 'supplier', 'user']),
        ], 201);
    }

    /**
     * Display the specified purchase.
     */
    public function show(Purchase $purchase)
    {
        abort_unless(in_array($purchase->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        return $purchase->load(['items.product', 'supplier', 'user']);
    }

    /**
     * Remove the specified purchase from storage (and reverse stock).
     */
    public function destroy(Purchase $purchase)
    {
        abort_unless(in_array($purchase->store_id, auth()->user()->getAccessibleStoreIds()), 404);

        DB::transaction(function () use ($purchase) {
            // Reverse stock
            foreach ($purchase->items as $item) {
                if ($item->product) {
                    $item->product->decrement('stock', $item->quantity);

                    \App\Models\StockMutation::create([
                        'store_id'   => $purchase->store_id,
                        'product_id' => $item->product_id,
                        'user_id'    => auth()->id(),
                        'type'       => 'out',
                        'quantity'   => $item->quantity,
                        'reference'  => 'Batal ' . $purchase->purchase_number,
                        'notes'      => 'Pembatalan/penghapusan transaksi pembelian',
                    ]);
                }
            }

            // If it was paid immediately, we should record a correction mutation in General Cash (Kas Besar) to restore balance
            if (in_array($purchase->payment_method, ['cash', 'transfer'])) {
                $mutCount = GeneralCashMutation::whereDate('created_at', Carbon::today())->count();
                $refNumber = 'KBS-' . Carbon::now()->format('Ymd') . '-' . str_pad($mutCount + 1, 4, '0', STR_PAD_LEFT);
                
                GeneralCashMutation::create([
                    'store_id' => $purchase->store_id,
                    'user_id' => auth()->id(),
                    'reference_number' => $refNumber,
                    'type' => 'koreksi',
                    'direction' => 'in',
                    'amount' => $purchase->total,
                    'source' => $purchase->supplier?->name ?? 'Supplier',
                    'destination' => 'Kas Besar Toko',
                    'notes' => 'Koreksi pembatalan pembelian ' . $purchase->purchase_number,
                ]);
            }

            $purchase->delete();
        });

        return response()->json([
            'message' => 'Pembelian berhasil dibatalkan dan stok produk telah dikoreksi kembali.'
        ]);
    }

    /**
     * Pay/settle debt for a purchase (installment or full).
     */
    public function payDebt(Request $request, Purchase $purchase)
    {
        $user = auth()->user();
        $storeId = $user->store_id;
        abort_unless($purchase->store_id === $storeId, 404);
        abort_unless($purchase->payment_method === 'debt', 422, 'Transaksi ini tidak menggunakan metode pembayaran hutang.');
        abort_unless($purchase->payment_status !== 'PAID', 422, 'Hutang untuk transaksi ini sudah lunas.');

        $remainingDebt = max(0, $purchase->total - $purchase->paid_amount);

        $payload = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:' . $remainingDebt],
        ]);

        $amount = (float) $payload['amount'];

        $purchase = DB::transaction(function () use ($purchase, $amount, $storeId, $user) {
            $newPaidAmount = $purchase->paid_amount + $amount;
            $newStatus = $newPaidAmount >= $purchase->total ? 'PAID' : 'PARTIAL';

            $purchase->update([
                'paid_amount' => $newPaidAmount,
                'payment_status' => $newStatus,
            ]);

            // Record to General Cash Mutation (Kas Besar)
            $mutCount = GeneralCashMutation::whereDate('created_at', Carbon::today())->count();
            $refNumber = 'KBS-' . Carbon::now()->format('Ymd') . '-' . str_pad($mutCount + 1, 4, '0', STR_PAD_LEFT);
            
            $supplierName = $purchase->supplier?->name ?? 'Supplier';

            GeneralCashMutation::create([
                'store_id' => $storeId,
                'user_id' => $user->id,
                'reference_number' => $refNumber,
                'type' => 'pembelian_barang',
                'direction' => 'out',
                'amount' => $amount,
                'source' => 'Kas Besar Toko',
                'destination' => $supplierName,
                'notes' => 'Pelunasan hutang pembelian ' . $purchase->purchase_number . ' ke ' . $supplierName,
            ]);

            return $purchase;
        });

        return response()->json([
            'message' => 'Pembayaran hutang berhasil dicatat.',
            'purchase' => $purchase->load(['items', 'supplier', 'user']),
        ]);
    }
}
