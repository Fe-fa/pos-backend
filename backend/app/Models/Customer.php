<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasUuid;

    protected $table = 'customers';
    protected $primaryKey = 'customer_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'full_name',
        'email',
        'phone',
        'current_balance',
    ];

    protected $casts = [
        'current_balance' => 'decimal:2',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class, 'customer_id', 'customer_id');
    }
}
