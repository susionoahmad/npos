<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierCashMutation extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'cashier_session_id',
        'mutation_number',
        'type', // tambah, kurang, koreksi, pengeluaran
        'direction', // in, out
        'amount',
        'notes',
        'reference_number',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class);
    }
}
