<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'purchase_orders';
    protected $primaryKey = 'purchase_order_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'user_id',
        'supplier_id',
        'po_number',
        'status',
        'order_date',
        'expected_delivery_date',
        'notes',
        'subtotal',
        'tax_amount',
        'final_total',
        'email_sent_at',
        'dispatched_at',
        'completed_at',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'final_total' => 'decimal:2',
        'email_sent_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
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
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id', 'purchase_order_id')
            ->orderBy('sort_order')
            ->orderBy('purchase_order_item_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class, 'purchase_order_id', 'purchase_order_id')
            ->orderByDesc('supplier_ledger_entry_id');
    }

    public function getRouteKeyName(): string
    {
        return 'purchase_order_id';
    }
}
