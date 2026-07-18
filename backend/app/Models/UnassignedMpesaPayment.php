<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnassignedMpesaPayment extends Model
{
    use HasFactory;

    protected $table = 'unassigned_mpesa_payments';
    protected $primaryKey = 'unassigned_mpesa_payment_id';

    protected $fillable = [
        'store_id',
        'mpesa_transaction_id',
        'amount',
        'phone_number',
        'customer_name',
        'bill_ref_number',
        'status',
        'conflict_flagged',
        'candidate_billing_ids',
        'candidate_attempt_ids',
        'claimed_terminal_id',
        'claimed_billing_id',
        'claimed_by_user_id',
        'resolved_at',
        'payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'conflict_flagged' => 'boolean',
        'candidate_billing_ids' => 'array',
        'candidate_attempt_ids' => 'array',
        'resolved_at' => 'datetime',
        'payload' => 'array',
    ];
}
