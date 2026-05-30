<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $table = 'stores';
    protected $primaryKey = 'store_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'store_name',
        'location',
        'currency',
        'logo_url',
        'telephone',
        'pin',
        'physical_address',
        'email_address',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'default_store_id', 'store_id');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_stores', 'store_id', 'user_id')
            ->withPivot('assigned_at');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'store_id', 'store_id');
    }

    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class, 'store_id', 'store_id');
    }

    public function documentSequences(): HasMany
    {
        return $this->hasMany(DocumentSequence::class, 'store_id', 'store_id');
    }
}
