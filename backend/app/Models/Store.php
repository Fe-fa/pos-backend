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
        'mpesa_enabled',
        'mpesa_environment',
        'mpesa_shortcode_type',
        'mpesa_shortcode',
        'mpesa_till_number',
        'mpesa_consumer_key',
        'mpesa_consumer_secret',
        'mpesa_passkey',
        'mpesa_callback_base_url',
        'mpesa_account_reference_prefix',
    ];

    protected $hidden = [
        'mpesa_consumer_key',
        'mpesa_consumer_secret',
        'mpesa_passkey',
    ];

    protected $appends = [
        'mpesa_consumer_key_set',
        'mpesa_consumer_secret_set',
        'mpesa_passkey_set',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'mpesa_enabled' => 'boolean',
        'mpesa_consumer_key' => 'encrypted',
        'mpesa_consumer_secret' => 'encrypted',
        'mpesa_passkey' => 'encrypted',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getMpesaConsumerKeySetAttribute(): bool
    {
        return filled($this->attributes['mpesa_consumer_key'] ?? null);
    }

    public function getMpesaConsumerSecretSetAttribute(): bool
    {
        return filled($this->attributes['mpesa_consumer_secret'] ?? null);
    }

    public function getMpesaPasskeySetAttribute(): bool
    {
        return filled($this->attributes['mpesa_passkey'] ?? null);
    }

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