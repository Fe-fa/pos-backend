<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $table = 'suppliers';
    protected $primaryKey = 'supplier_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'supplier_name',
        'store_id',
        'is_active',
        'contact_person',
        'phone',
        'email',
        'address',
        'opening_balance',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'opening_balance' => 'float',
    ];

    protected $appends = ['total_invoiced', 'total_paid', 'balance'];

    /** All GRNs raised against this supplier. */
    public function grns()
    {
        return $this->hasMany(Grn::class, 'supplier_id', 'supplier_id');
    }

    /** Only posted GRNs count toward the ledger — drafts aren't committed yet. */
    public function postedGrns()
    {
        return $this->grns()->where('status', 'completed');
    }

    public function getTotalInvoicedAttribute(): float
    {
        return (float) ($this->attributes['total_invoiced'] ?? $this->postedGrns()->sum('final_total'));
    }

    public function getTotalPaidAttribute(): float
    {
        return (float) ($this->attributes['total_paid'] ?? $this->postedGrns()->sum('paid_amount'));
    }

    /**
     * Positive = we owe the supplier. Negative = supplier owes us (overpaid/credit).
     */
    public function getBalanceAttribute(): float
    {
        return round((float) $this->opening_balance + $this->total_invoiced - $this->total_paid, 2);
    }
}