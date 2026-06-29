<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSubscriptionSlot extends Model
{
    protected $fillable = [
        'tenant_id',
        'slot_type',
        'slot_index',
        'amount_paid',
        'starts_at',
        'expires_at',
        'status',
        'midtrans_order_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'amount_paid' => 'integer',
        'slot_index' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where('expires_at', '>', now());
    }

    public function scopeBase($query)
    {
        return $query->where('slot_type', 'base');
    }

    public function scopeAddon($query)
    {
        return $query->where('slot_type', 'addon');
    }

    // Helpers
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at?->isFuture();
    }

    public function daysRemaining(): int
    {
        if (!$this->expires_at || !$this->isActive()) return 0;
        return (int) max(0, now()->diffInDays($this->expires_at, false));
    }
}
