<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpesaAccountBalance extends Model
{
    use HasUuid;

    protected $table = 'mpesa_account_balances';
    protected $primaryKey = 'mpesa_account_balance_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'shortcode',
        'identifier_type',
        'preferred_account_type',
        'account_name',
        'currency_code',
        'available_balance',
        'working_balance',
        'utility_balance',
        'raw_balance_text',
        'originator_conversation_id',
        'conversation_id',
        'status',
        'result_code',
        'result_desc',
        'requested_at',
        'received_at',
        'request_payload',
        'callback_payload',
        'meta',
    ];

    protected $casts = [
        'available_balance' => 'decimal:2',
        'working_balance' => 'decimal:2',
        'utility_balance' => 'decimal:2',
        'requested_at' => 'datetime',
        'received_at' => 'datetime',
        'request_payload' => 'array',
        'callback_payload' => 'array',
        'meta' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function isFresh(int $maxAgeSeconds): bool
    {
        return $this->status === 'success'
            && $this->received_at !== null
            && $this->received_at->gte(now()->subSeconds(max(1, $maxAgeSeconds)));
    }
}
