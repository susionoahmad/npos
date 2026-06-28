<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierCashMutation;
use App\Models\CashierSession;
use App\Models\GeneralCashMutation;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CashierSessionController extends Controller
{
    /**
     * Display a listing of cashier sessions.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $storeIds = $user->getAccessibleStoreIds();

        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) {
            return response()->json([]);
        }

        $query = CashierSession::with('user')
            ->whereIn('store_id', $storeIds);

        if ($request->has('from')) {
            $query->whereDate('opened_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->whereDate('opened_at', '<=', $request->to);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $sessions = $query->latest()->get();

        return response()->json($sessions);
    }

    /**
     * Get the current active session for the authenticated user in their store.
     */
    public function getActive(Request $request)
    {
        $user = $request->user();
        if (!$user->store_id) {
            return response()->json(null)->setContent('null');
        }

        $activeSession = CashierSession::query()
            ->where('store_id', $user->store_id)
            ->where('user_id', $user->id)
            ->where('status', 'AKTIF')
            ->first();

        if (!$activeSession) {
            return response()->json(null)->setContent('null');
        }

        return response()->json($activeSession);
    }

    /**
     * Open a new cashier shift session
     */
    public function open(Request $request)
    {
        $user = $request->user();
        abort_unless($user->store_id, 422, 'Pengguna belum memiliki toko aktif.');

        // Validate request
        $payload = $request->validate([
            'shift' => ['required', 'string', 'in:Pagi,Siang,Malam'],
            'start_balance' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        // Check if there is already an active session
        $existing = CashierSession::query()
            ->where('store_id', $user->store_id)
            ->where('user_id', $user->id)
            ->where('status', 'AKTIF')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Anda masih memiliki sesi aktif (' . $existing->session_number . '). Tutup sesi tersebut terlebih dahulu.',
            ], 422);
        }

        // Check if kas besar has sufficient balance for the requested modal awal
        if ($payload['start_balance'] > 0) {
            $kasBesarIn  = (float) GeneralCashMutation::where('store_id', $user->store_id)
                ->where('direction', 'in')
                ->sum('amount');
            $kasBesarOut = (float) GeneralCashMutation::where('store_id', $user->store_id)
                ->where('direction', 'out')
                ->sum('amount');
            $kasBesarBalance = $kasBesarIn - $kasBesarOut;

            if ($payload['start_balance'] > $kasBesarBalance) {
                return response()->json([
                    'message' => sprintf(
                        'Modal awal melebihi saldo Kas Besar toko. Saldo Kas Besar saat ini: Rp %s. Kurangi nominal modal awal atau tambah saldo Kas Besar terlebih dahulu.',
                        number_format($kasBesarBalance, 0, ',', '.')
                    ),
                    'kas_besar_balance' => $kasBesarBalance,
                    'errors' => [
                        'start_balance' => [
                            sprintf('Modal awal (Rp %s) melebihi saldo Kas Besar (Rp %s).',
                                number_format($payload['start_balance'], 0, ',', '.'),
                                number_format($kasBesarBalance, 0, ',', '.')
                            )
                        ]
                    ]
                ], 422);
            }
        }

        // Generate unique session number
        $dateStr = Carbon::now()->format('Ymd');
        $todayCount = CashierSession::query()
            ->whereDate('opened_at', Carbon::today())
            ->count();
        $sessionNumber = 'SES-' . $dateStr . '-' . str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);

        // Create the session
        $session = CashierSession::create([
            'store_id' => $user->store_id,
            'user_id' => $user->id,
            'session_number' => $sessionNumber,
            'shift' => $payload['shift'],
            'start_balance' => $payload['start_balance'],
            'status' => 'AKTIF',
            'opened_at' => Carbon::now(),
            'notes' => $payload['notes'] ?? null,
        ]);

        // Auto-record modal awal sebagai mutasi kasir (rekening koran laci)
        if ($payload['start_balance'] > 0) {
            $dateStr    = Carbon::now()->format('Ymd');
            $mutCount   = CashierCashMutation::whereDate('created_at', Carbon::today())->count();
            $mutNumber  = 'MUT-' . $dateStr . '-' . str_pad($mutCount + 1, 4, '0', STR_PAD_LEFT);
            CashierCashMutation::create([
                'store_id'           => $user->store_id,
                'user_id'            => $user->id,
                'cashier_session_id' => $session->id,
                'mutation_number'    => $mutNumber,
                'type'               => 'modal_awal',
                'direction'          => 'in',
                'amount'             => $payload['start_balance'],
                'notes'              => 'Modal awal sesi ' . $sessionNumber,
                'reference_number'   => $sessionNumber,
            ]);

            // Auto-record modal awal kasir ke Kas Besar (Keluar dari Kas Besar)
            $kbsCount = GeneralCashMutation::whereDate('created_at', Carbon::today())->count();
            GeneralCashMutation::create([
                'store_id'           => $user->store_id,
                'user_id'            => $user->id,
                'reference_number'   => 'KBS-' . $dateStr . '-' . str_pad($kbsCount + 1, 4, '0', STR_PAD_LEFT),
                'type'               => 'modal_awal_kasir',
                'direction'          => 'out',
                'amount'             => $payload['start_balance'],
                'source'             => 'Kas Besar',
                'destination'        => 'Kasir ' . $payload['shift'] . ' (' . $sessionNumber . ')',
                'notes'              => 'Penyediaan modal awal otomatis untuk sesi kasir ' . $sessionNumber,
                'cashier_session_id' => $session->id,
            ]);
        }

        AuditLogger::log(
            'OPEN_CASHIER_SESSION',
            sprintf('Kasir %s membuka sesi %s (Shift: %s). Modal awal: Rp %s',
                $user->name, $sessionNumber, $payload['shift'],
                number_format($payload['start_balance'], 0, ',', '.')
            ),
            $user->store_id,
            $user->id
        );

        return response()->json([
            'message' => 'Sesi kasir berhasil dibuka.',
            'session' => $session,
        ], 201);
    }

    /**
     * Get active cashier session summary statistics (cash, qris, transfer sales, etc.)
     */
    public function getSummary(Request $request)
    {
        $user = $request->user();
        abort_unless($user->store_id, 422, 'Pengguna belum memiliki toko aktif.');

        $session = CashierSession::query()
            ->where('store_id', $user->store_id)
            ->where('user_id', $user->id)
            ->where('status', 'AKTIF')
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'Tidak ada sesi kasir aktif saat ini.',
            ], 404);
        }

        $salesCash = (float) $session->transactions()->where('payment_method', 'cash')->sum('total');
        $salesQris = (float) $session->transactions()->where('payment_method', 'qris')->sum('total');
        $salesTransfer = (float) $session->transactions()->where('payment_method', 'transfer')->sum('total');
        $salesCard = (float) $session->transactions()->where('payment_method', 'card')->sum('total');
        $salesTotal = (float) $session->transactions()->sum('total');

        // Include mutations summary
        $mutationsIn = (float) \App\Models\CashierCashMutation::query()
            ->where('cashier_session_id', $session->id)
            ->where('direction', 'in')
            ->sum('amount');

        $mutationsOut = (float) \App\Models\CashierCashMutation::query()
            ->where('cashier_session_id', $session->id)
            ->where('direction', 'out')
            ->sum('amount');

        return response()->json([
            'session' => $session,
            'sales_cash' => $salesCash,
            'sales_qris' => $salesQris,
            'sales_transfer' => $salesTransfer,
            'sales_card' => $salesCard,
            'sales_total' => $salesTotal,
            'returns_total' => 0.0,
            'mutations_in' => $mutationsIn,
            'mutations_out' => $mutationsOut,
        ]);
    }

    /**
     * Close the active cashier shift session
     */
    public function close(Request $request)
    {
        $user = $request->user();
        abort_unless($user->store_id, 422, 'Pengguna belum memiliki toko aktif.');

        $session = CashierSession::query()
            ->where('store_id', $user->store_id)
            ->where('user_id', $user->id)
            ->where('status', 'AKTIF')
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'Tidak ada sesi kasir aktif yang dapat ditutup.',
            ], 422);
        }

        $payload = $request->validate([
            'expenses_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_balance' => ['required', 'numeric', 'min:0'],
            'difference_reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $salesCash = (float) $session->transactions()->where('payment_method', 'cash')->sum('total');

        $mutationsIn = (float) \App\Models\CashierCashMutation::query()
            ->where('cashier_session_id', $session->id)
            ->where('direction', 'in')
            ->sum('amount');

        $mutationsOut = (float) \App\Models\CashierCashMutation::query()
            ->where('cashier_session_id', $session->id)
            ->where('direction', 'out')
            ->sum('amount');
        
        // Expected Cash balance = Mutations In - Mutations Out (before close sweep)
        $expected = $mutationsIn - $mutationsOut;
        $difference = $payload['actual_balance'] - $expected;

        if ($difference != 0 && empty($payload['difference_reason'])) {
            return response()->json([
                'message' => 'Alasan selisih kas wajib diisi jika terdapat selisih saldo.',
                'errors' => [
                    'difference_reason' => ['Alasan selisih kas wajib diisi jika terdapat selisih saldo.']
                ]
            ], 422);
        }

        // We automatically sweep the entire physical cash counted to Kas Besar
        $expenses = 0.0;
        $deposit = $payload['actual_balance'];

        $session->update([
            'status' => 'TUTUP',
            'expenses_amount' => $expenses,
            'deposit_amount' => $deposit,
            'expected_balance' => $expected,
            'end_balance' => $payload['actual_balance'],
            'difference_amount' => $difference,
            'difference_reason' => $payload['difference_reason'] ?? null,
            'closed_at' => Carbon::now(),
            'notes' => $payload['notes'] ?? $session->notes,
        ]);

        $dateStr = Carbon::now()->format('Ymd');

        // Auto-record setoran kasir ke mutasi kasir (rekening koran) - this sweeps out the actual cash deposited
        if ($deposit > 0) {
            $mutCount2 = CashierCashMutation::whereDate('created_at', Carbon::today())->count();
            CashierCashMutation::create([
                'store_id'           => $user->store_id,
                'user_id'            => $user->id,
                'cashier_session_id' => $session->id,
                'mutation_number'    => 'MUT-' . $dateStr . '-' . str_pad($mutCount2 + 1, 4, '0', STR_PAD_LEFT),
                'type'               => 'setor_kas',
                'direction'          => 'out',
                'amount'             => $deposit,
                'notes'              => 'Setoran ke Kas Besar sesi ' . $session->session_number,
                'reference_number'   => $session->session_number . '-DEP',
            ]);

            // Auto-record setoran kasir ke Kas Besar
            $kbsCount = GeneralCashMutation::whereDate('created_at', Carbon::today())->count();
            GeneralCashMutation::create([
                'store_id'           => $user->store_id,
                'user_id'            => $user->id,
                'reference_number'   => 'KBS-' . $dateStr . '-' . str_pad($kbsCount + 1, 4, '0', STR_PAD_LEFT),
                'type'               => 'setoran_kasir',
                'direction'          => 'in',
                'amount'             => $deposit,
                'source'             => 'Kasir ' . $session->shift . ' (' . $session->session_number . ')',
                'destination'        => 'Kas Besar',
                'notes'              => 'Setoran otomatis dari penutupan sesi kasir ' . $session->session_number,
                'cashier_session_id' => $session->id,
            ]);
        }

        // Auto-record discrepancy correction mutation to force drawer balance to exactly ZERO
        if ($difference != 0) {
            $mutCount3 = CashierCashMutation::whereDate('created_at', Carbon::today())->count();
            $dir = $difference < 0 ? 'out' : 'in';
            CashierCashMutation::create([
                'store_id'           => $user->store_id,
                'user_id'            => $user->id,
                'cashier_session_id' => $session->id,
                'mutation_number'    => 'MUT-' . $dateStr . '-' . str_pad($mutCount3 + 1, 4, '0', STR_PAD_LEFT),
                'type'               => 'koreksi',
                'direction'          => $dir,
                'amount'             => abs($difference),
                'notes'              => 'Penyesuaian selisih kas (' . ($difference < 0 ? 'kurang' : 'lebih') . ') saat tutup sesi ' . $session->session_number,
                'reference_number'   => $session->session_number . '-ADJ',
            ]);
        }

        // Audit log
        $auditDesc = sprintf(
            'Kasir %s menutup sesi %s (Shift: %s). Saldo awal: Rp %s, Penjualan tunai: Rp %s, Mutasi Masuk: Rp %s, Mutasi Keluar: Rp %s, Pengeluaran laci: Rp %s, Setoran: Rp %s. Saldo Seharusnya: Rp %s, Saldo Aktual: Rp %s, Selisih: Rp %s',
            $user->name,
            $session->session_number,
            $session->shift,
            number_format($session->start_balance, 0, ',', '.'),
            number_format($salesCash, 0, ',', '.'),
            number_format($mutationsIn, 0, ',', '.'),
            number_format($mutationsOut, 0, ',', '.'),
            number_format($session->expenses_amount, 0, ',', '.'),
            number_format($session->deposit_amount, 0, ',', '.'),
            number_format($session->expected_balance, 0, ',', '.'),
            number_format($session->end_balance, 0, ',', '.'),
            number_format($session->difference_amount, 0, ',', '.')
        );
        AuditLogger::log('CLOSE_CASHIER_SESSION', $auditDesc, $session->store_id, $user->id);

        return response()->json([
            'message' => 'Sesi kasir berhasil ditutup.',
            'session' => $session,
        ]);
    }
}
