<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneralCashMutation;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GeneralCashController extends Controller
{
    /**
     * Get a paginated list of Kas Besar mutations for the current store.
     */
    public function index(Request $request)
    {
        $user    = $request->user();
        $storeIds = $user->getAccessibleStoreIds();

        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) {
            return response()->json([]);
        }

        $query = GeneralCashMutation::with(['user', 'approver', 'cashierSession'])
            ->whereIn('store_id', $storeIds);

        // Filters
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if (!$request->filled('from') && !$request->filled('to')) {
            // Default: current month
            $query->whereMonth('created_at', Carbon::now()->month)
                  ->whereYear('created_at', Carbon::now()->year);
        }

        $mutations = $query->latest()->paginate(50);

        // Compute running balance summary for the period
        $totalIn  = GeneralCashMutation::whereIn('store_id', $storeIds)
            ->where('direction', 'in')
            ->sum('amount');
        $totalOut = GeneralCashMutation::whereIn('store_id', $storeIds)
            ->where('direction', 'out')
            ->sum('amount');

        return response()->json([
            'mutations'    => $mutations,
            'balance'      => (float) ($totalIn - $totalOut),
            'total_in'     => (float) $totalIn,
            'total_out'    => (float) $totalOut,
        ]);
    }

    /**
     * Store a new Kas Besar mutation.
     */
    public function store(Request $request)
    {
        $user    = $request->user();
        $storeId = $user->store_id;
        if ($user->role === 'owner' && !$storeId) {
            return response()->json(['message' => 'Please select a specific store first to record a mutation.'], 400);
        }
        abort_unless($storeId, 422, 'Pengguna belum memiliki toko aktif.');

        $payload = $request->validate([
            'type'        => ['required', 'string', 'in:modal_awal_toko,tambah_modal,modal_awal_kasir,setoran_kasir,pengeluaran_operasional,pembelian_barang,bayar_supplier,transfer_bank,penyetoran_bank,penarikan_operasional,koreksi'],
            'direction'   => ['required', 'string', 'in:in,out'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'source'      => ['nullable', 'string', 'max:100'],
            'destination' => ['nullable', 'string', 'max:100'],
            'notes'       => ['required', 'string', 'min:3'],
        ]);

        // Generate unique reference number
        $dateStr      = Carbon::now()->format('Ymd');
        $todayCount   = GeneralCashMutation::whereDate('created_at', Carbon::today())->count();
        $refNumber    = 'KBS-' . $dateStr . '-' . str_pad($todayCount + 1, 4, '0', STR_PAD_LEFT);

        $mutation = GeneralCashMutation::create([
            'store_id'         => $storeId,
            'user_id'          => $user->id,
            'reference_number' => $refNumber,
            'type'             => $payload['type'],
            'direction'        => $payload['direction'],
            'amount'           => $payload['amount'],
            'source'           => $payload['source'] ?? null,
            'destination'      => $payload['destination'] ?? null,
            'notes'            => $payload['notes'],
        ]);

        AuditLogger::log(
            'KAS_BESAR_MUTATION',
            sprintf(
                'User %s mencatat transaksi Kas Besar: %s sebesar Rp %s. Ref: %s. Keterangan: %s',
                $user->name,
                $mutation->type_label,
                number_format($mutation->amount, 0, ',', '.'),
                $mutation->reference_number,
                $mutation->notes
            ),
            $storeId,
            $user->id
        );

        return response()->json([
            'message'  => 'Transaksi Kas Besar berhasil dicatat.',
            'mutation' => $mutation->load('user'),
        ], 201);
    }

    /**
     * Get the current Kas Besar balance for the store.
     */
    public function balance(Request $request)
    {
        $user    = $request->user();
        $storeIds = $user->getAccessibleStoreIds();

        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) {
            return response()->json(['balance' => 0, 'total_in' => 0, 'total_out' => 0]);
        }

        $totalIn  = (float) GeneralCashMutation::whereIn('store_id', $storeIds)->where('direction', 'in')->sum('amount');
        $totalOut = (float) GeneralCashMutation::whereIn('store_id', $storeIds)->where('direction', 'out')->sum('amount');

        return response()->json([
            'balance'   => $totalIn - $totalOut,
            'total_in'  => $totalIn,
            'total_out' => $totalOut,
        ]);
    }
}
