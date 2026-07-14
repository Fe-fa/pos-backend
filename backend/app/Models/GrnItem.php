<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GrnItem extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'grn_items';
    protected $primaryKey = 'grn_item_id';

    protected $fillable = [
        'uuid',
        'grn_id',
        'po_item_id',
        'product_id',
        'product_name_snapshot',
        'barcode',
        'brand_name',
        'item_type',
        'batch_no',
        'expiry_date',
        'quantity_expected',
        'qty_received',
        'quantity_accepted',
        'quantity_rejected',
        'free_qty',
        'cost_price_excl_tax',
        'tax_rate',
        'cess_amount',
        'tax_type',
        'hsn_code',
        'prod_code',
        'cost_price_incl_tax',
        'mrp',
        'selling_price',
        'scheme_discount_percent',
        'scheme_discount_amount',
        'key_discount_percent',
        'key_discount_amount',
        'cash_discount_amount',
        'total_discount_amount',
        'taxable_amount',
        'tax_amount',
        'total_amount',
        'low_inventory_level',
        'category_name',
        'subcategory_name',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'quantity_expected' => 'integer',
        'qty_received' => 'integer',
        'quantity_accepted' => 'integer',
        'quantity_rejected' => 'integer',
        'free_qty' => 'integer',
        'cost_price_excl_tax' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'cess_amount' => 'decimal:2',
        'cost_price_incl_tax' => 'decimal:2',
        'mrp' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'scheme_discount_percent' => 'decimal:2',
        'scheme_discount_amount' => 'decimal:2',
        'key_discount_percent' => 'decimal:2',
        'key_discount_amount' => 'decimal:2',
        'cash_discount_amount' => 'decimal:2',
        'total_discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'low_inventory_level' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class, 'grn_id', 'grn_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'po_item_id', 'purchase_order_item_id');
    }

    public function getRouteKeyName(): string
    {
        return 'grn_item_id';
    }
}
