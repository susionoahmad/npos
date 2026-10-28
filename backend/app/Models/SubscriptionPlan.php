<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'slug',
        'name',
        'tagline',
        'price_monthly',
        'price_yearly',
        'max_stores',
        'max_users',
        'features',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_monthly' => 'integer',
        'price_yearly'  => 'integer',
        'max_stores'    => 'integer',
        'max_users'     => 'integer',
        'features'      => 'array',
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order', 'asc');
    }
}