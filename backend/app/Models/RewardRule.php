<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardRule extends Model
{
    protected $table    = 'reward_rules';
    protected $fillable = [
        'store_id', 'rule_name',
        'points_per_shilling', 'min_spend_required',
        'point_value', 'min_redemption_points',
        'is_active', 'start_date', 'end_date',
        // Chapa 5
        'chapa5_enabled', 'chapa5_buy_count',
        'chapa5_free_count', 'chapa5_label',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'chapa5_enabled'      => 'boolean',
        'chapa5_buy_count'    => 'integer',
        'chapa5_free_count'   => 'integer',
        'start_date'          => 'datetime',
        'end_date'            => 'datetime',
        'points_per_shilling' => 'decimal:4',
        'point_value'         => 'decimal:4',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function scopeActiveForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')
                  ->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now());
            })
            ->orderByDesc('id')
            ->limit(1);
    }
}