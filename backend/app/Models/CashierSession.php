<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'session_number',
        'shift',
        'start_balance',
        'expenses_amount',
        'deposit_amount',
        'expected_balance',
        'end_balance',
        'difference_amount',
        'difference_reason',
        'status', // 'AKTIF', 'TUTUP'
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'start_balance' => 'float',
        'expenses_amount' => 'float',
        'deposit_amount' => 'float',
        'expected_balance' => 'float',
        'end_balance' => 'float',
        'difference_amount' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
