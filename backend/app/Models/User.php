<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
    protected $guard_name = 'sanctum';

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
        'password',
        'role',
        'default_store_id',
        'shift_name',
        'shift_start',
        'shift_end',
        'verification_code',
        'verification_expiry',
        'password_reset_token',
        'password_reset_expiry',
        'is_active',
        'is_verified',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
        'password_reset_token',
    ];

    protected $appends = [
        'full_name',
        'shift_label',
        'sales_today',
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
    return 'sanctum';
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

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Payment::class,
            Billing::class,
            'user_id',     // Foreign key on billing table...
            'billing_id',  // Foreign key on payments table...
            'user_id',     // Local key on users table...
            'billing_id'   // Local key on billing table...
        );
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getShiftLabelAttribute(): ?string
    {
        $name = trim((string) $this->shift_name);
        $start = $this->normalizeTime($this->shift_start);
        $end = $this->normalizeTime($this->shift_end);

        if ($name !== '' && $start && $end) {
            return "{$name} ({$start} - {$end})";
        }

        if ($name !== '') {
            return $name;
        }

        if ($start && $end) {
            return "{$start} - {$end}";
        }

        return null;
    }

    public function getSalesTodayAttribute(): float
    {
        if (array_key_exists('sales_today', $this->attributes)) {
            return round((float) $this->attributes['sales_today'], 2);
        }

        return round((float) $this->payments()
            ->whereDate('payments.payment_date', now()->toDateString())
            ->sum('payments.amount_received'), 2);
    }

    private function normalizeTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = (string) $value;

        return strlen($value) >= 5 ? substr($value, 0, 5) : $value;
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
    public function sessions(): HasMany
{
    return $this->hasMany(Session::class, 'user_id', 'user_id');
}
}
