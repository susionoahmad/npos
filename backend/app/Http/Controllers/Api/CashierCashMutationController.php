<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierCashMutation;
use App\Models\CashierSession;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CashierCashMutationController extends Controller
{
    /**
     * Display a listing of cashier cash mutations.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $storeIds = $user->getAccessibleStoreIds();

        if (empty($storeIds) || ($user->role !== 'owner' && empty($user->store_id))) {
            return response()->json([]);
        }

        $query = CashierCashMutation::with(['user', 'cashierSession'])
            ->whereIn('store_id', $storeIds);

        // Filters
        if ($request->has('cashier_session_id')) {
            $query->where('cashier_session_id', $request->cashier_session_id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        } else {
            // Default to today only when no session/user/date filter given
            $hasSpecificFilter = $request->has('cashier_session_id')
                || $request->has('user_id')
                || $request->has('from');
            if (!$hasSpecificFilter) {
                $query->whereDate('created_at', Carbon::today());
            }
        }

        $perPage = min((int) ($request->per_page ?? 100), 500);
        $mutations = $query->latest()->paginate($perPage);

        return response()->json($mutations);
    }

    /**
     * Store a newly created cashier cash mutation.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $storeId = $user->store_id;
        if ($user->role === 'owner' && !$storeId) {
            return response()->json(['message' => 'Please select a specific store first to record cashier mutations.'], 400);
        }

        abort_unless($storeId, 422, 'Pengguna belum memiliki toko aktif.');

        // 1. Verify active cashier session exists
        $activeSession = CashierSession::query()
            ->where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->where('status', 'AKTIF')
            ->first();

        if (!$activeSession) {
            return response()->json([
                'message' => 'Sesi kasir aktif belum dibuka. Silakan buka sesi kasir terlebih dahulu untuk mencatat mutasi kas.',
            ], 422);
        }

        // 2. Validate input
        $payload = $request->validate([
            'type' => ['required', 'string', 'in:tambah,kurang,koreksi,pengeluaran'],
            'direction' => [
                Rule::requiredIf($request->type === 'koreksi'),
                'nullable',
                'string',
                'in:in,out'
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['required', 'string', 'min:3'],
            'reference_number' => ['nullable', 'string', 'max:50'],
        ]);

        // 3. Determine direction automatically if not 'koreksi'
        $direction = $payload['direction'] ?? 'out';
        if ($payload['type'] === 'tambah') {
            $direction = 'in';
        } elseif ($payload['type'] === 'kurang' || $payload['type'] === 'pengeluaran') {
            $direction = 'out';
        }

        // 4. Generate unique mutation number
        $dateStr = Carbon::now()->format('Ymd');
        $todayCount = CashierCashMutation::query()
            ->whereDate('created_at', Carbon::today())
            ->count();
        $mutationNumber = 'MUT-' . $dateStr . '-' . str_pad($todayCount + 1, 4, '0', STR_PAD_LEFT);

        // 5. Save mutation
        $mutation = CashierCashMutation::create([
            'store_id' => $storeId,
            'user_id' => $user->id,
            'cashier_session_id' => $activeSession->id,
            'mutation_number' => $mutationNumber,
            'type' => $payload['type'],
            'direction' => $direction,
            'amount' => $payload['amount'],
            'notes' => $payload['notes'],
            'reference_number' => $payload['reference_number'] ?? null,
        ]);

        // 6. Record to Audit Trail
        $typeLabel = match ($mutation->type) {
            'tambah' => 'Tambah Kas',
            'kurang' => 'Kurang Kas',
            'koreksi' => 'Koreksi Kas (' . ($mutation->direction === 'in' ? 'Tambah' : 'Kurang') . ')',
            'pengeluaran' => 'Pengeluaran Operasional',
            default => $mutation->type
        };

        $auditDesc = sprintf(
            'Kasir %s mencatat mutasi %s sebesar Rp %s. Sesi: %s, Keterangan: "%s". Ref: %s',
            $user->name,
            $typeLabel,
            number_format($mutation->amount, 0, ',', '.'),
            $activeSession->session_number,
            $mutation->notes,
            $mutation->reference_number ?? '-'
        );

        AuditLogger::log('CREATE_CASH_MUTATION', $auditDesc, $storeId, $user->id);

        return response()->json([
            'message' => 'Mutasi kas kasir berhasil dicatat.',
            'mutation' => $mutation->load('user'),
        ], 201);
    }
}
