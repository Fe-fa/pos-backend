<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Grn extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'grns';
    protected $primaryKey = 'grn_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'user_id',
        'supplier_id',
        'grn_number',
        'invoice_number',
        'invoice_date',
        'grn_date',
        'supplier_name',
        'is_po_available',
        'po_number',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'additional_discount_1',
        'additional_discount_2',
        'other_charges',
        'round_off',
        'grand_total',
        'final_total',
        'paid_amount',
        'balance_due',
        'payment_status',
        'release_to_inventory',
        'last_payment_at',
        'notes',
        'completed_at',
        'stock_applied_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'grn_date' => 'date',
        'is_po_available' => 'boolean',
        'release_to_inventory' => 'boolean',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'additional_discount_1' => 'decimal:2',
        'additional_discount_2' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'round_off' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'final_total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'last_payment_at' => 'datetime',
        'completed_at' => 'datetime',
        'stock_applied_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GrnItem::class, 'grn_id', 'grn_id')
            ->orderBy('sort_order')
            ->orderBy('grn_item_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(GrnPayment::class, 'grn_id', 'grn_id')
            ->orderByDesc('grn_payment_id');
    }

    public function getRouteKeyName(): string
    {
        return 'grn_id';
    }
}
