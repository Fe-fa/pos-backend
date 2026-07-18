<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GrnPayment extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'grn_payments';
    protected $primaryKey = 'grn_payment_id';

    protected $fillable = [
        'uuid',
        'grn_id',
        'store_id',
        'user_id',
        'payment_voucher_id',
        'payment_number',
        'payment_voucher_number',
        'payment_method',
        'status',
        'amount_paid',
        'amount_received',
        'amount_tendered',
        'change_returned',
        'mpesa_phone',
        'mpesa_code',
        'card_reference',
        'card_holder',
        'bank_reference',
        'notes',
        'paid_at',
        'slip_number',
        'slip_type',
        'slip_generated_at',
        'installment_number',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'amount_received' => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_returned' => 'decimal:2',
        'paid_at' => 'datetime',
        'slip_generated_at' => 'datetime',
        'installment_number' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class, 'grn_id', 'grn_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function paymentVoucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id', 'payment_voucher_id');
    }

    public function getRouteKeyName(): string
    {
        return 'grn_payment_id';
    }
}
