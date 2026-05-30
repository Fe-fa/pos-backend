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
        'payment_method',
        'amount_received',
        'amount_tendered',
        'change_returned',
        'balance_before',
        'balance_after',
        'payment_date',
    ];

    protected $casts = [
        'amount_received' => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_returned' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'payment_date' => 'datetime',
    ];

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id', 'billing_id');
    }
}
