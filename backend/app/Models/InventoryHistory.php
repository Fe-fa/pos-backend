<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryHistory extends Model
{
    use HasUuid;
    
    protected $table = 'inventory_histories';
    protected $primaryKey = 'inventory_history_id';

    protected $fillable = [
        'uuid',
        'inventory_id',
        'store_id',
        'product_id',
        'batch_no',
        'quantity_before',
        'quantity_changed',
        'quantity_after',
        'change_type',
        'reference',
        'reason',
        'user_id',
    ];

    protected $casts = [
        'quantity_before'  => 'integer',
        'quantity_changed' => 'integer',
        'quantity_after'   => 'integer',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id', 'inventory_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
