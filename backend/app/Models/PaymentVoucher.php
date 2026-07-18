<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentVoucher extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'payment_vouchers';
    protected $primaryKey = 'payment_voucher_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'supplier_id',
        'grn_id',
        'purchase_order_id',
        'prepared_by_user_id',
        'approved_by_user_id',
        'voucher_number',
        'voucher_date',
        'delivery_note_no',
        'invoice_number',
        'payee_name',
        'payee_address',
        'payment_method',
        'payment_account',
        'cheque_no',
        'cheque_date',
        'amount',
        'paid_amount',
        'balance_due',
        'status',
        'authorized_by',
        'authorized_signature',
        'authorized_date',
        'line_items',
        'notes',
        'receipt_number',
        'receipt_generated_at',
        'receipt_generated_by_user_id',
        'receipt_payment_breakdown',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'cheque_date' => 'date',
        'authorized_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'line_items' => 'array',
        'receipt_generated_at' => 'datetime',
        'receipt_payment_breakdown' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class, 'grn_id', 'grn_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id', 'purchase_order_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id', 'user_id');
    }

    public function receiptGeneratedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receipt_generated_by_user_id', 'user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(GrnPayment::class, 'payment_voucher_id', 'payment_voucher_id')
            ->orderByDesc('grn_payment_id');
    }

    public function settledPayments(): HasMany
    {
        return $this->hasMany(GrnPayment::class, 'payment_voucher_id', 'payment_voucher_id')
            ->whereIn('status', ['completed', 'posted'])
            ->orderBy('paid_at')
            ->orderBy('grn_payment_id');
    }

    public function getRouteKeyName(): string
    {
        return 'payment_voucher_id';
    }
}
