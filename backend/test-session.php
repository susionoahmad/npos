<?php

use App\Models\User;
use App\Models\CashierSession;
use Illuminate\Support\Carbon;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Find a cashier user
$cashier = User::where('role', 'cashier')->first();

if (!$cashier) {
    echo "No cashier user found!\n";
    exit(1);
}

echo "Testing cashier: {$cashier->name} (Store ID: {$cashier->store_id})\n";

// Clear existing sessions
CashierSession::where('user_id', $cashier->id)->delete();
echo "Cleared existing sessions for this cashier.\n";

// Emulate calling open API logic
$storeId = $cashier->store_id;

$existing = CashierSession::query()
    ->where('store_id', $storeId)
    ->where('user_id', $cashier->id)
    ->where('status', 'AKTIF')
    ->first();

if ($existing) {
    echo "Active session already exists: {$existing->session_number}\n";
    exit(1);
}

// Generate unique session number
$dateStr = Carbon::now()->format('Ymd');
$todayCount = CashierSession::query()
    ->whereDate('opened_at', Carbon::today())
    ->count();
$sessionNumber = 'SES-' . $dateStr . '-' . str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);

$session = CashierSession::create([
    'store_id' => $storeId,
    'user_id' => $cashier->id,
    'session_number' => $sessionNumber,
    'shift' => 'Pagi',
    'start_balance' => 100000,
    'status' => 'AKTIF',
    'opened_at' => Carbon::now(),
    'notes' => 'Testing open session',
]);

echo "Created session successfully: {$session->session_number} in status {$session->status}\n";
