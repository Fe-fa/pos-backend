<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasUuid;

    protected $table      = 'customers';
    protected $primaryKey = 'customer_id';

    protected $fillable = [
        'uuid', 'store_id', 'full_name', 'email', 'phone',
        'current_balance',
        'loyalty_points', 'total_earned_points',
        'punch_card_count', 'total_free_items_earned',
    ];

    protected $casts = [
        'current_balance'        => 'decimal:2',
        'loyalty_points'         => 'integer',
        'total_earned_points'    => 'integer',
        'punch_card_count'       => 'integer',
        'total_free_items_earned'=> 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class, 'customer_id', 'customer_id');
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'customer_id', 'customer_id');
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? $this->primaryKey, $value)->firstOrFail();
    }
}