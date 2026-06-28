<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierCashMutation;
use App\Models\GeneralCashMutation;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Daily summary for the dashboard (today only).
     */
    public function dailySummary()
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) {
            return [
                'total_sales' => 0,
                'total_transactions' => 0,
                'average_order_value' => 0,
                'total_discount' => 0,
                'total_tax' => 0,
                'payment_methods' => [],
                'top_products' => [],
                'recent_transactions' => [],
            ];
        }
        
        $today = now()->toDateString();
        $queryToday = Transaction::whereIn('store_id', $storeIds)->whereDate('created_at', $today);

        $totalSales = (float) $queryToday->sum('total');
        $totalTrx = $queryToday->count();
        $avgValue = $totalTrx > 0 ? round($totalSales / $totalTrx, 2) : 0;
        $totalDiscount = (float) $queryToday->sum('discount_amount');
        $totalTax = (float) $queryToday->sum('tax_amount');

        // Payment method breakdown
        $paymentMethods = Transaction::whereIn('store_id', $storeIds)
            ->whereDate('created_at', $today)
            ->select('payment_method', DB::raw('sum(total) as total_amount'), DB::raw('count(*) as count'))
            ->groupBy('payment_method')
            ->get()
            ->map(fn($item) => [
                'method' => $item->payment_method,
                'amount' => (float) $item->total_amount,
                'count' => (int) $item->count,
            ]);

        // Top products today
        $topProducts = TransactionItem::whereHas('transaction', function ($q) use ($storeIds, $today) {
                $q->whereIn('store_id', $storeIds)->whereDate('created_at', $today);
            })
            ->select('product_id', 'product_name', DB::raw('sum(quantity) as qty_sold'), DB::raw('sum(line_total) as total_revenue'))
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get();

        // Recent 5 transactions
        $recentTransactions = Transaction::with(['user'])
            ->whereIn('store_id', $storeIds)
            ->latest()
            ->limit(5)
            ->get();

        return [
            'total_sales' => $totalSales,
            'total_transactions' => $totalTrx,
            'average_order_value' => $avgValue,
            'total_discount' => $totalDiscount,
            'total_tax' => $totalTax,
            'payment_methods' => $paymentMethods,
            'top_products' => $topProducts,
            'recent_transactions' => $recentTransactions,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // LAPORAN OPERASIONAL HARIAN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Penjualan per kasir dalam periode.
     * GET /reports/sales-by-cashier?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function salesByCashier(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        [$from, $to] = $this->dateRange($request);
        $userId = $request->filled('user_id') ? (int) $request->user_id : null;

        $baseQuery = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

        if ($userId) {
            $baseQuery->where('user_id', $userId);
        }

        $rows = (clone $baseQuery)
            ->with('user')
            ->select(
                'user_id',
                DB::raw('sum(total) as total_sales'),
                DB::raw('count(*) as total_transactions'),
                DB::raw('sum(discount_amount) as total_discount'),
                DB::raw('sum(tax_amount) as total_tax')
            )
            ->groupBy('user_id')
            ->get()
            ->map(fn($r) => [
                'user_id'            => $r->user_id,
                'cashier_name'       => $r->user?->name ?? 'Unknown',
                'total_sales'        => (float) $r->total_sales,
                'total_transactions' => (int) $r->total_transactions,
                'total_discount'     => (float) $r->total_discount,
                'total_tax'          => (float) $r->total_tax,
            ]);

        // Daily breakdown (filtered by cashier if selected)
        $daily = (clone $baseQuery)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('sum(total) as total_sales'),
                DB::raw('count(*) as total_transactions')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn($r) => [
                'date'               => $r->date,
                'total_sales'        => (float) $r->total_sales,
                'total_transactions' => (int) $r->total_transactions,
            ]);

        return response()->json([
            'by_cashier'  => $rows->values(),
            'by_date'     => $daily->values(),
            'from'        => $from,
            'to'          => $to,
            'grand_total' => (float) $rows->sum('total_sales'),
            'grand_trx'   => (int) $rows->sum('total_transactions'),
            'filtered_user_id' => $userId,
        ]);
    }

    /**
     * Rekap pembayaran per metode dalam periode.
     * GET /reports/payment-recap?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function paymentRecap(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        [$from, $to] = $this->dateRange($request);
        $userId = $request->filled('user_id') ? (int) $request->user_id : null;

        $query = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $rows = $query
            ->select(
                'payment_method',
                DB::raw('sum(total) as total_amount'),
                DB::raw('count(*) as total_transactions'),
                DB::raw('avg(total) as avg_transaction')
            )
            ->groupBy('payment_method')
            ->get()
            ->map(fn($r) => [
                'method'             => $r->payment_method,
                'total_amount'       => (float) $r->total_amount,
                'total_transactions' => (int) $r->total_transactions,
                'avg_transaction'    => (float) $r->avg_transaction,
            ]);

        return response()->json([
            'rows'             => $rows->values(),
            'grand_total'      => (float) $rows->sum('total_amount'),
            'from'             => $from,
            'to'               => $to,
            'filtered_user_id' => $userId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // LAPORAN BARANG & STOK
    // ─────────────────────────────────────────────────────────────────

    /**
     * Barang terlaris dalam periode.
     * GET /reports/top-products?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=20
     */
    public function topProducts(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        [$from, $to] = $this->dateRange($request);
        $limit = min((int) ($request->limit ?? 20), 100);

        $rows = TransactionItem::whereHas('transaction', function ($q) use ($storeIds, $from, $to) {
                $q->whereIn('store_id', $storeIds)
                  ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);
            })
            ->select(
                'product_id',
                'product_name',
                DB::raw('sum(quantity) as qty_sold'),
                DB::raw('sum(line_total) as total_revenue'),
                DB::raw('avg(price) as avg_price')
            )
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('qty_sold')
            ->limit($limit)
            ->get()
            ->map(fn($r) => [
                'product_id'    => $r->product_id,
                'product_name'  => $r->product_name,
                'qty_sold'      => (int) $r->qty_sold,
                'total_revenue' => (float) $r->total_revenue,
                'avg_price'     => (float) $r->avg_price,
            ]);

        return response()->json([
            'rows'  => $rows->values(),
            'from'  => $from,
            'to'    => $to,
            'total' => count($rows),
        ]);
    }

    /**
     * Barang tidak laku (sedikit atau nol terjual) dalam periode.
     * GET /reports/slow-products?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function slowProducts(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        [$from, $to] = $this->dateRange($request);

        // Semua produk milik toko
        $allProducts = Product::whereIn('store_id', $storeIds)
            ->select('id', 'name', 'barcode', 'stock', 'price')
            ->orderBy('name')
            ->get();

        // Qty terjual per produk dalam periode
        $soldMap = TransactionItem::whereHas('transaction', function ($q) use ($storeIds, $from, $to) {
                $q->whereIn('store_id', $storeIds)
                  ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);
            })
            ->select('product_id', DB::raw('sum(quantity) as qty_sold'))
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $rows = $allProducts->map(fn($p) => [
                'product_id'   => $p->id,
                'product_name' => $p->name,
                'barcode'      => $p->barcode,
                'stock'        => (int) $p->stock,
                'price'        => (float) $p->price,
                'qty_sold'     => (int) ($soldMap->get($p->id)?->qty_sold ?? 0),
            ])
            ->sortBy('qty_sold')
            ->values();

        return response()->json([
            'rows'  => $rows,
            'from'  => $from,
            'to'    => $to,
            'total' => count($rows),
        ]);
    }

    /**
     * Stok saat ini semua produk.
     * GET /reports/stock-current?search=xxx&low_stock=1
     */
    public function stockCurrent(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        $query = Product::with('category')
            ->whereIn('store_id', $storeIds);

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(fn($sq) => $sq->where('name', 'like', "%$q%")->orWhere('barcode', 'like', "%$q%"));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->boolean('low_stock')) {
            $query->where('stock', '<=', 5);
        }

        $products = $query->orderBy('name')->get()->map(fn($p) => [
            'product_id'   => $p->id,
            'product_name' => $p->name,
            'barcode'      => $p->barcode,
            'category'     => $p->category?->name ?? '-',
            'stock'        => (int) $p->stock,
            'price'        => (float) $p->price,
            'is_low_stock' => $p->stock <= 5,
        ]);

        $stats = [
            'total_products'  => count($products),
            'low_stock_count' => $products->where('is_low_stock', true)->count(),
            'out_of_stock'    => $products->where('stock', 0)->count(),
        ];

        return response()->json([
            'rows'  => $products->values(),
            'stats' => $stats,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // LAPORAN KEUANGAN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Mutasi kas gabungan (kas besar + kasir) dalam periode.
     * GET /reports/cash-flow?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function cashFlow(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        [$from, $to] = $this->dateRange($request);
        $userId = $request->filled('user_id') ? (int) $request->user_id : null;

        // Kas Besar mutations (not filtered by cashier — kas besar is store-level)
        $kasBesarQuery = GeneralCashMutation::with(['user', 'cashierSession'])
            ->whereIn('store_id', $storeIds)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

        if ($userId) {
            $kasBesarQuery->where('user_id', $userId);
        }

        $kasBesar = $kasBesarQuery->orderBy('created_at')
            ->get()
            ->map(fn($m) => [
                'source'     => 'kas_besar',
                'ref'        => $m->reference_number,
                'date'       => $m->created_at->toDateTimeString(),
                'type'       => $m->type,
                'type_label' => $m->type_label ?? $m->type,
                'direction'  => $m->direction,
                'amount'     => (float) $m->amount,
                'notes'      => $m->notes,
                'user'       => $m->user?->name ?? '-',
            ]);

        // Kasir mutations (filtered by cashier session owner if user_id given)
        $kasirQuery = CashierCashMutation::with(['user', 'cashierSession'])
            ->whereHas('cashierSession', fn($q) => $q->whereIn('store_id', $storeIds))
            ->whereBetween(DB::raw('DATE(cashier_cash_mutations.created_at)'), [$from, $to]);

        if ($userId) {
            $kasirQuery->where('cashier_cash_mutations.user_id', $userId);
        }

        $kasirMuts = $kasirQuery->orderBy('cashier_cash_mutations.created_at')
            ->get()
            ->map(fn($m) => [
                'source'     => 'kasir',
                'ref'        => $m->reference_number ?? $m->mutation_number ?? '-',
                'date'       => $m->created_at->toDateTimeString(),
                'type'       => $m->type,
                'type_label' => $m->type,
                'direction'  => $m->direction,
                'amount'     => (float) $m->amount,
                'notes'      => $m->notes,
                'user'       => $m->user?->name ?? '-',
                'cashier_name' => $m->user?->name ?? '-',
            ]);

        // Summary
        $kasBesarIn  = $kasBesar->where('direction', 'in')->sum('amount');
        $kasBesarOut = $kasBesar->where('direction', 'out')->sum('amount');
        $kasirIn     = $kasirMuts->where('direction', 'in')->sum('amount');
        $kasirOut    = $kasirMuts->where('direction', 'out')->sum('amount');

        return response()->json([
            'kas_besar'        => $kasBesar->values(),
            'kasir'            => $kasirMuts->values(),
            'summary'          => [
                'kas_besar_in'  => (float) $kasBesarIn,
                'kas_besar_out' => (float) $kasBesarOut,
                'kas_besar_net' => (float) ($kasBesarIn - $kasBesarOut),
                'kasir_in'      => (float) $kasirIn,
                'kasir_out'     => (float) $kasirOut,
                'kasir_net'     => (float) ($kasirIn - $kasirOut),
            ],
            'from'             => $from,
            'to'               => $to,
            'filtered_user_id' => $userId,
        ]);
    }

    /**
     * Laporan Laba Rugi estimasi dalam periode.
     * GET /reports/profit-loss?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function profitLoss(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        [$from, $to] = $this->dateRange($request);

        // Revenue dari penjualan per hari
        $salesRows = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('sum(total) as revenue'),
                DB::raw('sum(discount_amount) as discount'),
                DB::raw('sum(tax_amount) as tax'),
                DB::raw('count(*) as transactions')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        $totalRevenue  = (float) $salesRows->sum('revenue');
        $totalDiscount = (float) $salesRows->sum('discount');
        $totalTax      = (float) $salesRows->sum('tax');

        // HPP (Harga Pokok Penjualan)
        $totalHpp = (float) \App\Models\TransactionItem::whereHas('transaction', function ($q) use ($storeIds, $from, $to) {
                $q->whereIn('store_id', $storeIds)
                  ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);
            })
            ->leftJoin('products', 'transaction_items.product_id', '=', 'products.id')
            ->sum(DB::raw('COALESCE(transaction_items.buying_price, products.buying_price, 0) * transaction_items.quantity'));

        // Pengeluaran operasional dari kas besar
        $expenses = (float) GeneralCashMutation::whereIn('store_id', $storeIds)
            ->where('direction', 'out')
            ->whereIn('type', ['pengeluaran_operasional', 'pembelian_barang'])
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->sum('amount');

        // Pengeluaran dari kasir
        $cashierExpenses = (float) CashierCashMutation::whereHas('cashierSession', fn($q) => $q->whereIn('store_id', $storeIds))
            ->where('direction', 'out')
            ->where('type', 'pengeluaran')
            ->whereBetween(DB::raw('DATE(cashier_cash_mutations.created_at)'), [$from, $to])
            ->sum('amount');

        $totalOpex   = $expenses + $cashierExpenses;
        $netRevenue  = $totalRevenue - $totalDiscount;
        $grossProfit = $netRevenue - $totalHpp;
        $netProfit   = $grossProfit - $totalOpex;

        return response()->json([
            'summary' => [
                'revenue'          => $totalRevenue,
                'discount'         => $totalDiscount,
                'tax'              => $totalTax,
                'net_revenue'      => $netRevenue,
                'total_hpp'        => $totalHpp,
                'gross_profit'     => $grossProfit,
                'opex'             => $totalOpex,
                'opex_kas_besar'   => $expenses,
                'opex_kasir'       => $cashierExpenses,
                'net_profit'       => $netProfit,
                'net_margin_pct'   => $totalRevenue > 0 ? round($netProfit / $totalRevenue * 100, 2) : 0,
            ],
            'daily' => $salesRows->map(fn($r) => [
                'date'         => $r->date,
                'revenue'      => (float) $r->revenue,
                'discount'     => (float) $r->discount,
                'tax'          => (float) $r->tax,
                'transactions' => (int) $r->transactions,
            ])->values(),
            'from' => $from,
            'to'   => $to,
        ]);
    }

    /**
     * Summary of product purchases (Pembelian).
     * GET /reports/purchase-summary?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function purchaseSummary(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        [$from, $to] = $this->dateRange($request);

        // 1. Purchases by Period (Daily)
        $purchasesByPeriod = \App\Models\Purchase::whereIn('store_id', $storeIds)
            ->whereBetween('purchase_date', [$from, $to])
            ->select('purchase_date', DB::raw('sum(total) as total_amount'), DB::raw('count(*) as count'))
            ->groupBy('purchase_date')
            ->orderBy('purchase_date', 'asc')
            ->get()
            ->map(fn($item) => [
                'date' => $item->purchase_date->toDateString(),
                'amount' => (float) $item->total_amount,
                'count' => (int) $item->count,
            ]);

        // 2. Purchases by Supplier
        $purchasesBySupplier = \App\Models\Purchase::whereIn('purchases.store_id', $storeIds)
            ->whereBetween('purchases.purchase_date', [$from, $to])
            ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->select(
                DB::raw('COALESCE(suppliers.name, "(Tanpa Supplier)") as supplier_name'),
                DB::raw('sum(purchases.total) as total_amount'),
                DB::raw('count(purchases.id) as count')
            )
            ->groupBy('purchases.supplier_id', 'suppliers.name')
            ->orderByDesc('total_amount')
            ->get()
            ->map(fn($item) => [
                'supplier_name' => $item->supplier_name,
                'amount' => (float) $item->total_amount,
                'count' => (int) $item->count,
            ]);

        // 3. Supplier Debt (Hutang Supplier)
        $supplierDebt = \App\Models\Purchase::whereIn('purchases.store_id', $storeIds)
            ->where('purchases.payment_method', 'debt')
            ->whereIn('purchases.payment_status', ['PENDING', 'PARTIAL'])
            ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->select(
                DB::raw('COALESCE(suppliers.name, "(Tanpa Supplier)") as supplier_name'),
                'suppliers.phone as supplier_phone',
                DB::raw('sum(purchases.total - purchases.paid_amount) as total_debt'),
                DB::raw('count(purchases.id) as pending_invoices_count')
            )
            ->groupBy('purchases.supplier_id', 'suppliers.name', 'suppliers.phone')
            ->orderByDesc('total_debt')
            ->get()
            ->map(fn($item) => [
                'supplier_name' => $item->supplier_name,
                'supplier_phone' => $item->supplier_phone,
                'total_debt' => (float) $item->total_debt,
                'pending_invoices_count' => (int) $item->pending_invoices_count,
            ]);

        // 4. Overall Totals
        $totalsQuery = \App\Models\Purchase::whereIn('store_id', $storeIds)
            ->whereBetween('purchase_date', [$from, $to]);
        
        $totalPurchasesAmount = (float) $totalsQuery->sum('total');
        $totalPurchasesCount = $totalsQuery->count();

        // Overall pending debt (all time)
        $totalDebtAmount = (float) \App\Models\Purchase::whereIn('store_id', $storeIds)
            ->where('payment_method', 'debt')
            ->whereIn('payment_status', ['PENDING', 'PARTIAL'])
            ->sum(DB::raw('total - paid_amount'));

        return response()->json([
            'summary' => [
                'total_purchases_amount' => $totalPurchasesAmount,
                'total_purchases_count'  => $totalPurchasesCount,
                'total_debt_amount'       => $totalDebtAmount,
            ],
            'by_period'   => $purchasesByPeriod,
            'by_supplier' => $purchasesBySupplier,
            'supplier_debt' => $supplierDebt,
            'from'        => $from,
            'to'          => $to,
        ]);
    }

    /**
     * Laporan Mutasi Stok barang (masuk/keluar).
     * GET /reports/stock-mutations?from=YYYY-MM-DD&to=YYYY-MM-DD&product_id=xxx
     */
    public function stockMutations(Request $request)
    {
        $user = auth()->user();
        $storeIds = $user->getAccessibleStoreIds();
        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) return response()->json([]);

        [$from, $to] = $this->dateRange($request);
        $productId = $request->filled('product_id') ? (int) $request->product_id : null;

        $query = \App\Models\StockMutation::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->with(['product', 'user']);

        if ($productId) {
            $query->where('product_id', $productId);
        }

        $mutations = $query->latest('created_at')->get()->map(fn($item) => [
            'type' => $item->type,
            'product_id' => $item->product_id,
            'product_name' => $item->product?->name ?? 'Unknown',
            'quantity' => (int) $item->quantity,
            'reference' => $item->reference,
            'date' => $item->created_at->toDateTimeString(),
            'user_name' => $item->user?->name ?? '-',
            'notes' => $item->notes ?? '-',
        ]);

        return response()->json([
            'rows' => $mutations,
            'from' => $from,
            'to' => $to,
            'filtered_product_id' => $productId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────

    /**
     * Return [from, to] date strings from request, default = current month.
     */
    private function dateRange(Request $request): array
    {
        $from = $request->filled('from') ? $request->from : now()->startOfMonth()->toDateString();
        $to   = $request->filled('to')   ? $request->to   : now()->toDateString();
        return [$from, $to];
    }
}
