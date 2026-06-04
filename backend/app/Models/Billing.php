<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'fulfillment_status',
        'fulfillment_type',
    ];

    protected $appends = [
        'order_number',
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
                $total = (float) $model->total;
                $paid = (float) $model->paid_amount;

                $isFullyPaid = ($total > 0 && $paid >= $total);

                if ($model->is_draft || $model->status === 'draft' || $paid === 0.00) {
                    $isFullyPaid = false;
                }

                $documentType = $isFullyPaid ? 'receipt' : 'invoice';

                $documentService = app(\App\Services\DocumentNumberService::class);
                $model->invnumber = $documentService->nextNumber($model->store_id, $documentType);
            }

            if (empty($model->fulfillment_status)) {
                $model->fulfillment_status = 'pending';
            }

            if (empty($model->fulfillment_type)) {
                $model->fulfillment_type = 'walk_in_counter';
            }
        });
    }

    public function getOrderNumberAttribute(): string
    {
        return 'ORD-' . str_pad((string) $this->billing_id, 4, '0', STR_PAD_LEFT);
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
