<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    use HasUuid;

    protected $table = 'inventory';
    protected $primaryKey = 'inventory_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'product_id',
        'batch_no',
        'quantity',
        'reorder_level',
    ];

    protected $casts = [
        'quantity'      => 'integer',
        'reorder_level' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(InventoryHistory::class, 'inventory_id', 'inventory_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeFifo(Builder $query): Builder
    {
        return $query->orderBy('created_at')->orderBy('inventory_id');
    }
}
