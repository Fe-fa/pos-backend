<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivePaymentAttempt extends Model
{
    use HasFactory;

    protected $table = 'active_payment_attempts';
    protected $primaryKey = 'active_payment_attempt_id';

    protected $fillable = [
        'store_id',
        'billing_id',
        'user_id',
        'terminal_id',
        'expected_amount',
        'status',
        'initiated_at',
        'expires_at',
        'claimed_at',
        'settled_at',
        'cancelled_at',
        'split_allocations',
        'points_redeemed',
        'meta',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'initiated_at' => 'datetime',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'settled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'split_allocations' => 'array',
        'meta' => 'array',
    ];
}
