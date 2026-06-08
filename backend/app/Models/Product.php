<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Store; 

class Product extends Model
{
    use HasUuid;

    protected $table = 'products';
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'category_id',
        'sku',
        'product_name',
        'price',
        'cost_price',
        'vat_rate',
        'image_url',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if (!$value) {
                    return null;
                }

                if (filter_var($value, FILTER_VALIDATE_URL)) {
                    return $value;
                }

                return url(Storage::url($value));
            }
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'product_id', 'product_id');
    }

    public function billingItems(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'product_id', 'product_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id', 'product_id');
    }
public function store(): BelongsTo
{
    return $this->belongsTo(Store::class, 'store_id', 'store_id');
}
}
