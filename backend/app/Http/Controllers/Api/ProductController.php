<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $query = Product::with(['category', 'supplier'])
            ->whereIn('store_id', $user->getAccessibleStoreIds());
        if ($search = request('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%"));
        }
        if ($categoryId = request('category_id')) {
            $query->where('category_id', $categoryId);
        }
        $perPage = request('per_page', 20);
        return $query->latest()->paginate($perPage);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request)
    {
        $storeId = auth()->user()->store_id;
        if (auth()->user()->role === 'owner' && !$storeId) {
            return response()->json(['message' => 'Please select a specific store first to create a product.'], 400);
        }
        
        $product = Product::create(array_merge($request->validated(), [
            'store_id' => $storeId,
        ]));

        if ($product->stock > 0) {
            \App\Models\StockMutation::create([
                'store_id'   => $product->store_id,
                'product_id' => $product->id,
                'user_id'    => auth()->id(),
                'type'       => 'in',
                'quantity'   => $product->stock,
                'reference'  => 'Stok Awal',
                'notes'      => 'Pembuatan produk secara manual',
            ]);
        }

        return $product;
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        abort_unless(in_array($product->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        return $product->load(['category', 'supplier']);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        abort_unless(in_array($product->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        $oldStock = $product->stock;
        $product->update($request->validated());
        $newStock = $product->stock;
        $diff = $newStock - $oldStock;
        
        if ($diff != 0) {
            \App\Models\StockMutation::create([
                'store_id'   => $product->store_id,
                'product_id' => $product->id,
                'user_id'    => auth()->id(),
                'type'       => $diff > 0 ? 'in' : 'out',
                'quantity'   => abs($diff),
                'reference'  => 'Penyesuaian Stok',
                'notes'      => 'Pembaruan produk secara manual (stok diubah dari ' . $oldStock . ' menjadi ' . $newStock . ')',
            ]);
        }

        return $product->refresh();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        abort_unless(in_array($product->store_id, auth()->user()->getAccessibleStoreIds()), 404);
        $product->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function expiryWarnings()
    {
        return Product::whereIn('store_id', auth()->user()->getAccessibleStoreIds())
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', Carbon::today()->addDays(30))
            ->orderBy('expiry_date')
            ->get();
    }

    public function importCsv(Request $request)
    {
        $storeId = auth()->user()->store_id;
        if (auth()->user()->role === 'owner' && !$storeId) {
            return response()->json(['message' => 'Please select a specific store first to import products.'], 400);
        }
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);
        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath()));
        $headers = array_map('trim', array_shift($rows));
        $inserted = 0;
        foreach ($rows as $row) {
            $data = array_combine($headers, $row);
            if (! $data || empty($data['name']) || ! isset($data['price'])) {
                continue;
            }

            $barcode = isset($data['barcode']) ? trim($data['barcode']) : null;
            if ($barcode && preg_match('/^[0-9.]+[eE]\+?[0-9]+$/', $barcode)) {
                $barcode = sprintf('%.0f', (double) $barcode);
            }

            $product = Product::create([
                'store_id' => $storeId,
                'name' => $data['name'],
                'barcode' => $barcode,
                'price' => (float) $data['price'],
                'buying_price' => (float) ($data['buying_price'] ?? $data['harga_beli'] ?? 0),
                'stock' => (int) ($data['stock'] ?? 0),
                'expiry_date' => $data['expiry_date'] ?? null,
            ]);

            if ($product->stock > 0) {
                \App\Models\StockMutation::create([
                    'store_id'   => $storeId,
                    'product_id' => $product->id,
                    'user_id'    => auth()->id(),
                    'type'       => 'in',
                    'quantity'   => $product->stock,
                    'reference'  => 'Import CSV',
                    'notes'      => 'Stok awal dari import CSV',
                ]);
            }

            $inserted++;
        }
        return response()->json(['inserted' => $inserted]);
    }

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            return response()->json([
                'path' => $path,
                'url' => asset('storage/' . $path),
            ]);
        }

        return response()->json(['message' => 'No image uploaded'], 400);
    }
}
