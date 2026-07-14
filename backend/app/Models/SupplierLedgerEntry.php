<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierLedgerEntry extends Model
{
    protected $table = 'supplier_ledger_entries';
    protected $primaryKey = 'supplier_ledger_entry_id';

    protected $fillable = [
        'supplier_id',
        'store_id',
        'purchase_order_id',
        'grn_id',
        'grn_payment_id',
        'created_by_user_id',
        'entry_type',
        'direction',
        'reference_number',
        'description',
        'amount',
        'entry_date',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'datetime',
        'meta' => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id', 'purchase_order_id');
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class, 'grn_id', 'grn_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(GrnPayment::class, 'grn_payment_id', 'grn_payment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }
}
