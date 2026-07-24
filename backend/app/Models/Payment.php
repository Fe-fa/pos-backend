<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuid;

    protected $table = 'payments';
    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'uuid',
        'billing_id',
        'receiptnumber',
        'payment_reference',
        'payment_method',
        'status',
        'amount_received',
        'amount_tendered',
        'change_returned',
        'balance_before',
        'balance_after',
        'payment_date',
        'mpesa_phone',
        'mpesa_receipt',
        'mpesa_mode',
        'card_reference',
        'card_holder',
        'cheque_bank_name',
        'cheque_bank_code',
        'cheque_number',
        'cheque_date',
        'cheque_account_name',
        'cheque_account_number',
        'cheque_branch_name',
        'cheque_status',
        'cheque_notes',
        'cheque_authorized_at',
        'cheque_authorized_by',
        'cheque_authorized_ip',
        'cheque_verified_at',
        'cheque_verified_by',
        'cheque_verified_ip',
        'cheque_submitted_at',
        'cheque_submitted_by',
        'cheque_submitted_ip',
        'cheque_deposited_at',
        'cheque_deposited_by',
        'cheque_deposited_ip',
        'cheque_deposit_reference',
        'cheque_cleared_at',
        'cheque_cleared_by',
        'cheque_cleared_ip',
        'cheque_clearing_reference',
        'cheque_return_code',
        'cheque_return_reason',
        'cheque_returned_at',
        'cheque_returned_by',
        'cheque_returned_ip',
        'payment_meta',
    ];

    protected $casts = [
        'amount_received' => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_returned' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'payment_date' => 'datetime',
        'cheque_date' => 'date',
        'cheque_authorized_at' => 'datetime',
        'cheque_verified_at' => 'datetime',
        'cheque_submitted_at' => 'datetime',
        'cheque_deposited_at' => 'datetime',
        'cheque_cleared_at' => 'datetime',
        'cheque_returned_at' => 'datetime',
        'payment_meta' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'payment_id';
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id', 'billing_id');
    }
}
