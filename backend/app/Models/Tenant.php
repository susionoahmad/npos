<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subscription_status',
        'trial_ends_at',
        'subscription_ends_at',
        'max_stores',
        'max_users',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'max_stores' => 'integer',
        'max_users' => 'integer',
    ];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptionSlots(): HasMany
    {
        return $this->hasMany(TenantSubscriptionSlot::class);
    }

    /**
     * Count active store slots (base + addon).
     * Returns the effective max_stores limit from active slots.
     */
    public function activeSlotStoreLimit(): int
    {
        // If in trial mode, use the stored max_stores limit
        if ($this->subscription_status === 'trial') {
            return (int) ($this->max_stores ?? 1);
        }

        $activeSlots = $this->subscriptionSlots()->active()->count();
        return max(1, $activeSlots); // at least 1
    }

    /**
     * Check if the base (slot 1) is active - required for all operations.
     */
    public function isBaseSlotActive(): bool
    {
        $now = now();

        if ($this->subscription_status === 'trial') {
            return $this->trial_ends_at ? $now->lt($this->trial_ends_at) : true;
        }

        if (in_array($this->subscription_status, ['expired', 'cancelled'])) {
            return false;
        }

        if ($this->subscription_ends_at && $now->gt($this->subscription_ends_at)) {
            return false;
        }

        if ($this->subscriptionSlots()->exists()) {
            return $this->subscriptionSlots()
                ->where('slot_type', 'base')
                ->active()
                ->exists();
        }

        return $this->subscription_status === 'active';
    }

    /**
     * Check and sync expired status dynamically.
     */
    public function checkAndSyncExpiredStatus(): bool
    {
        $now = now();
        $shouldExpire = false;

        if ($this->subscription_status === 'trial' && $this->trial_ends_at && $now->gt($this->trial_ends_at)) {
            $shouldExpire = true;
        } elseif ($this->subscription_status === 'active' && $this->subscription_ends_at && $now->gt($this->subscription_ends_at)) {
            $shouldExpire = true;
        }

        if ($shouldExpire) {
            $this->update(['subscription_status' => 'expired']);
            $this->stores()->update(['is_license_activated' => false]);
            $this->subscriptionSlots()
                ->where('status', 'active')
                ->where('expires_at', '<', $now)
                ->update(['status' => 'expired']);
            return true;
        }

        return false;
    }

    /**
     * Get the next available slot index for a new addon slot.
     */
    public function nextSlotIndex(): int
    {
        $max = $this->subscriptionSlots()->max('slot_index') ?? 1;
        return $max + 1;
    }
}
