<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierShift extends Model
{
    use HasFactory;

    protected $table = 'cashier_shifts';
    protected $primaryKey = 'cashier_shift_id';

    protected $fillable = [
        'store_id',
        'user_id',
        'business_date',
        'status',
        'opening_balance',
        'opening_note',
        'opened_at',
        'closed_at',
        'closed_by_user_id',
        'counted_cash',
        'expected_cash',
        'variance',
        'carry_forward_variance',
        'close_note',
        'summary_snapshot',
    ];

    protected $casts = [
        'business_date'           => 'date:Y-m-d',
        'opening_balance'         => 'decimal:2',
        'counted_cash'            => 'decimal:2',
        'expected_cash'           => 'decimal:2',
        'variance'                => 'decimal:2',
        'carry_forward_variance'  => 'decimal:2',
        'summary_snapshot'        => 'array',
        'opened_at'               => 'datetime',
        'closed_at'               => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id', 'user_id');
    }
}
