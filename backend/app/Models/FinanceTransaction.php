<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceTransaction extends Model
{
    use HasUuid;

    protected $table = 'finance_transactions';
    protected $primaryKey = 'finance_transaction_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'user_id',
        'cashier_shift_id',
        'transaction_type',
        'flow',
        'method',
        'category',
        'entity_name',
        'reference_no',
        'amount',
        'transaction_date',
        'status',
        'notes',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime',
        'meta' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id', 'cashier_shift_id');
    }
}
