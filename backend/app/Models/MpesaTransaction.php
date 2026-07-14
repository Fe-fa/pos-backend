<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpesaTransaction extends Model
{
    use HasUuid;

    protected $table = 'mpesa_transactions';
    protected $primaryKey = 'mpesa_transaction_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'billing_id',
        'grn_id',
        'payment_voucher_id',
        'user_id',
        'channel',
        'shortcode_type',
        'receiver_type',
        'idempotency_key',
        'merchant_request_id',
        'checkout_request_id',
        'mpesa_receipt',
        'conversation_id',
        'originator_conversation_id',
        'amount',
        'phone_number',
        'vendor_shortcode',
        'account_reference',
        'transaction_desc',
        'transaction_date',
        'status',
        'result_code',
        'result_desc',
        'request_payload',
        'callback_payload',
        'environment',
        'payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'callback_payload' => 'array',
        'transaction_date' => 'datetime',
    ];

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id', 'billing_id');
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class, 'grn_id', 'grn_id');
    }

    public function paymentVoucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id', 'payment_voucher_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'payment_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['success', 'failed', 'cancelled', 'timeout'], true);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
}
