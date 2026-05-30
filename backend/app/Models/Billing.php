<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class Billing extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'billing';
    protected $primaryKey = 'billing_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'customer_id',
        'user_id',
        'invnumber',
        'status',
        'subtotal',
        'vat_amount',
        'total',
        'paid_amount',
        'balance_due',
        'is_draft',
        'stock_applied_at',
        'billing_date',
        'notes',
    ];
    
    protected $casts = [
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'is_draft' => 'boolean',
        'stock_applied_at' => 'datetime',
        'billing_date' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->invnumber)) {
                // 1. Convert attributes to floats safely to prevent null matching 0
                $total = (float) $model->total;
                $paid = (float) $model->paid_amount;
                
                // 2. Strict calculation: Is it fully paid upfront?
                $isFullyPaid = ($total > 0 && $paid >= $total);

                // 3. Absolute Safeguard: If it is explicitly marked as a draft, or nothing has been paid, it is ALWAYS an invoice
                if ($model->is_draft || $model->status === 'draft' || $paid === 0.00) {
                    $isFullyPaid = false;
                }

                // 4. Assign the correct sequence path
                $documentType = $isFullyPaid ? 'receipt' : 'invoice';
                
                $documentService = app(\App\Services\DocumentNumberService::class);
                $model->invnumber = $documentService->nextNumber($model->store_id, $documentType);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'billing_id', 'billing_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'billing_id', 'billing_id');
    }

    public function getRouteKeyName()
    {
        return 'billing_id';
    }
}