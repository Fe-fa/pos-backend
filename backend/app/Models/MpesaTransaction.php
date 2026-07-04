<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MpesaTransaction — every M-Pesa attempt in a single, auditable ledger.
 *
 * Lifecycle:
 *   pending  → created locally, not yet sent to Daraja
 *   sent     → STK Push accepted, awaiting callback (or C2B accepted)
 *   success  → callback confirmed money received; linked Payment row created
 *   failed   → callback rejected (wrong PIN, insufficient funds, etc.)
 *   cancelled→ customer pressed cancel on their phone
 *   timeout  → no callback within the polling window
 */
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
        'user_id',
        'channel',
        'shortcode_type',
        'idempotency_key',
        'merchant_request_id',
        'checkout_request_id',
        'mpesa_receipt',
        'conversation_id',
        'originator_conversation_id',
        'amount',
        'phone_number',
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
        'amount'           => 'decimal:2',
        'request_payload'  => 'array',
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
