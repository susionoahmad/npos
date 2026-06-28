<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneralCashMutation extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'reference_number',
        'type',
        'direction',
        'amount',
        'source',
        'destination',
        'notes',
        'cashier_session_id',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'approved_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Human-readable label for type.
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'modal_awal_toko'       => 'Modal Awal Toko',
            'tambah_modal'          => 'Tambah Modal Toko',
            'modal_awal_kasir'      => 'Modal Awal Kasir',
            'setoran_kasir'         => 'Setoran Kasir',
            'pengeluaran_operasional' => 'Pengeluaran Operasional',
            'pembelian_barang'      => 'Pembelian Barang',
            'bayar_supplier'        => 'Bayar Supplier',
            'transfer_bank'         => 'Transfer Bank',
            'penyetoran_bank'       => 'Penyetoran Bank',
            'penarikan_operasional' => 'Penarikan Operasional',
            'koreksi'               => 'Koreksi Saldo',
            default                 => $this->type,
        };
    }
}
