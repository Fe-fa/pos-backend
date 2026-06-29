<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSession extends Model
{
    protected $table = 'pos_sessions';

    protected $fillable = [
        'user_id',
        'store_id',
        'billing_id',
        'selected_customer_id',
        'notes',
        'local_items',
    ];

    protected $casts = [
        'billing_id' => 'integer',
        'store_id' => 'integer',
        'user_id' => 'integer',
        'selected_customer_id' => 'integer',
        'local_items' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id', 'billing_id');
    }
}
