<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function register(array $data): User
    {
        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role' => User::ROLE_CASHIER,
            'default_store_id' => null,
            'shift_name' => null,
            'shift_start' => null,
            'shift_end' => null,
            'is_active' => true,
            'is_verified' => false,
            'email_verified_at' => app()->environment('local') ? now() : null,
        ]);

        $user->syncRoles([User::ROLE_CASHIER]);

        try {
            if (! app()->environment('local')) {
                $user->sendEmailVerificationNotification();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $user;
    }

    public function login(array $credentials): array
    {
        $user = User::with(['defaultStore', 'stores'])
            ->where('username', $credentials['username'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['This account is inactive.'],
            ]);
        }

        $deviceName = $credentials['device_name'] ?? request()->userAgent() ?? 'api-device';
        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => $this->profile($user),
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }

    public function profile(User $user): array
    {
        $user->loadMissing(['defaultStore', 'stores']);

        $hasStoreAssignment = $user->isAdmin()
            || (int) $user->stores->count() > 0
            || ! empty($user->default_store_id);

        $shiftStart = $this->normalizeTime($user->shift_start);
        $shiftEnd = $this->normalizeTime($user->shift_end);
        $shiftLabel = $this->buildShiftLabel($user->shift_name, $shiftStart, $shiftEnd);

        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'is_verified' => $user->is_verified,
            'email_verified' => $user->hasVerifiedEmail(),
            'default_store_id' => $user->default_store_id,
            'has_store_assignment' => $hasStoreAssignment,

            'shift_name' => $user->shift_name,
            'shift_start' => $shiftStart,
            'shift_end' => $shiftEnd,
            'shift_label' => $shiftLabel,

            'default_store' => $user->defaultStore ? [
                'store_id' => $user->defaultStore->store_id,
                'store_name' => $user->defaultStore->store_name,
                'currency' => $user->defaultStore->currency,
                'location' => $user->defaultStore->location,
                'physical_address' => $user->defaultStore->physical_address ?? $user->defaultStore->address,
                'telephone' => $user->defaultStore->telephone ?? $user->defaultStore->phone ?? $user->defaultStore->phone_number,
                'email_address' => $user->defaultStore->email_address ?? $user->defaultStore->email,
            ] : null,

            'stores' => $user->stores->map(fn ($store) => [
                'store_id' => $store->store_id,
                'store_name' => $store->store_name,
                'currency' => $store->currency,
                'location' => $store->location,
                'physical_address' => $store->physical_address ?? $store->address,
                'telephone' => $store->telephone ?? $store->phone ?? $store->phone_number,
                'email_address' => $store->email_address ?? $store->email,
            ])->values()->all(),

            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    private function normalizeTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = (string) $value;

        return strlen($value) >= 5 ? substr($value, 0, 5) : $value;
    }

    private function buildShiftLabel(?string $name, ?string $start, ?string $end): ?string
    {
        $name = trim((string) $name);

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
}
