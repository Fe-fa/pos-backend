<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'current_balance',
        'credit_days',
        'outstanding_balance',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'opening_balance' => 'float',
        'current_balance' => 'float',
        'outstanding_balance' => 'float',
        'credit_days' => 'integer',
    ];

    protected $appends = ['balance'];

    public function grns(): HasMany
    {
        return $this->hasMany(Grn::class, 'supplier_id', 'supplier_id');
    }

    public function postedGrns(): HasMany
    {
        return $this->grns()->where('status', 'completed');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id', 'supplier_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class, 'supplier_id', 'supplier_id')
            ->orderByDesc('supplier_ledger_entry_id');
    }

    public function refreshCurrentBalance(): float
    {
        $debits = (float) $this->ledgerEntries()->where('direction', 'debit')->sum('amount');
        $credits = (float) $this->ledgerEntries()->where('direction', 'credit')->sum('amount');
        $balance = round((float) $this->opening_balance + $debits - $credits, 2);

        $this->forceFill([
            'current_balance' => $balance,
            'outstanding_balance' => $balance,
        ])->save();

        return $balance;
    }

    public function getBalanceAttribute(): float
    {
        return round((float) ($this->current_balance ?? $this->outstanding_balance ?? $this->opening_balance), 2);
    }
}
