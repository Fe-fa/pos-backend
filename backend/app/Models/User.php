<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_CASHIER = 'cashier';

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    protected string $guard_name = 'sanctum';

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
        'password',
        'role',
        'default_store_id',
        'verification_code',
        'verification_expiry',
        'is_active',
        'is_verified',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
    ];

    protected $appends = [
        'full_name',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'verification_expiry' => 'datetime',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'password' => 'hashed',
    ];

    public function getDefaultGuardName(): string
    {
        return $this->guard_name;
    }

    public function getRouteKeyName(): string
    {
        return 'user_id';
    }

    public function defaultStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_store_id', 'store_id');
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'user_stores', 'user_id', 'store_id')
            ->withPivot('assigned_at');
    }

    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class, 'user_id', 'user_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isAdmin(): bool
    {
        if (($this->role ?? null) === self::ROLE_ADMIN) {
            return true;
        }

        // Spatie role check
        try {
            return $this->hasRole(self::ROLE_ADMIN, 'sanctum')
                || $this->getRoleNames()->contains(self::ROLE_ADMIN);
        } catch (\Throwable $e) {
            return false;
        }
    }
    public function isManager(): bool
    {
        if (($this->role ?? null) === self::ROLE_MANAGER) {
            return true;
        }

        try {
            return $this->hasRole(self::ROLE_MANAGER, 'sanctum')
                || $this->getRoleNames()->contains(self::ROLE_MANAGER);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isCashier(): bool
    {
        if (($this->role ?? null) === self::ROLE_CASHIER) {
            return true;
        }

        try {
            return $this->hasRole(self::ROLE_CASHIER, 'sanctum')
                || $this->getRoleNames()->contains(self::ROLE_CASHIER);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
