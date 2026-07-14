<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillingItem extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'billing_items';
    protected $primaryKey = 'billing_item_id';

    protected $fillable = [
        'uuid',
        'billing_id',
        'product_id',
        'quantity',
        'unit_price',
        'unit_selling_price',
        'unit_cost_price',
        'line_subtotal',
        'vat_rate',
        'vat_amount',
        'total_amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_selling_price' => 'decimal:2',
        'unit_cost_price' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'deleted_at' => 'datetime',
    ];

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id', 'billing_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function getRouteKeyName(): string
    {
        return 'billing_item_id';
    }
}
