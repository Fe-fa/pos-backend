<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderItem extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'purchase_order_items';
    protected $primaryKey = 'purchase_order_item_id';

    protected $fillable = [
        'uuid',
        'purchase_order_id',
        'product_id',
        'product_name_snapshot',
        'sku_snapshot',
        'quantity_ordered',
        'quantity_received',
        'quantity_rejected_total',
        'unit_cost',
        'tax_rate',
        'line_total',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'quantity_rejected_total' => 'integer',
        'unit_cost' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'line_total' => 'decimal:2',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'quantity_remaining',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id', 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function getQuantityRemainingAttribute(): int
    {
        return max((int) $this->quantity_ordered - (int) $this->quantity_received, 0);
    }

    public function getRouteKeyName(): string
    {
        return 'purchase_order_item_id';
    }
}
