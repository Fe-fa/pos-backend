<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
public function register(array $data): User
{
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $user = User::create([
        'first_name'        => $data['first_name'],
        'last_name'         => $data['last_name'],
        'username'          => $data['username'],
        'email'             => $data['email'],
        'phone'             => $data['phone'] ?? null,
        'password'          => $data['password'],
        'role'              => User::ROLE_CASHIER,
        'is_active'         => true,
        'is_verified'       => false,
        'email_verified_at' => null,          
        'verification_code'   => bcrypt($code),
        'verification_expiry' => now()->addMinutes(15),
    ]);

    $user->syncRoles([User::ROLE_CASHIER]);

    try {
        $user->notify(new \App\Notifications\EmailVerificationCode($code));
    } catch (\Throwable $e) {
        report($e);
    }

    return $user;
}

public function verifyEmailCode(User $user, string $code): void
{
    if ($user->hasVerifiedEmail()) {
        return; // already verified — no-op
    }

    if (
        empty($user->verification_code) ||
        empty($user->verification_expiry) ||
        now()->isAfter($user->verification_expiry)
    ) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'code' => ['Verification code has expired. Please request a new one.'],
        ]);
    }

    if (! \Hash::check($code, $user->verification_code)) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'code' => ['Invalid verification code.'],
        ]);
    }

    $user->forceFill([
        'email_verified_at'  => now(),
        'is_verified'        => true,
        'verification_code'  => null,
        'verification_expiry'=> null,
    ])->save();
}

public function resendVerificationCode(User $user): void
{
    if ($user->hasVerifiedEmail()) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'email' => ['Email is already verified.'],
        ]);
    }

    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $user->forceFill([
        'verification_code'   => bcrypt($code),
        'verification_expiry' => now()->addMinutes(15),
    ])->save();

    $user->notify(new \App\Notifications\EmailVerificationCode($code));
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

 $expiresAt = config('sanctum.expiration')
    ? now()->addMinutes((int) config('sanctum.expiration'))
    : null;

        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        $session = $this->writeSession($user, $token->accessToken->id);

        return [
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt?->toDateTimeString(),
            'refresh_expires_at' => now()
                ->addMinutes(config('sanctum.refresh_expiration', config('sanctum.expiration', 0)))
                ->toDateTimeString(),
            'session_id' => $session->id,
            'user' => $this->profile($user),
        ];
    }

    public function refresh(User $user): array
    {
        $currentToken = $user->currentAccessToken();

        if (! $currentToken) {
            throw ValidationException::withMessages([
                'token' => ['No active token found.'],
            ]);
        }

        $refreshWindow = config('sanctum.refresh_expiration');
        if ($refreshWindow !== null) {
            $createdAt = $currentToken->created_at;
            if ($createdAt->addMinutes($refreshWindow)->isPast()) {
                $currentToken->delete();

                throw ValidationException::withMessages([
                    'token' => ['Refresh window expired. Please log in again.'],
                ]);
            }
        }

        $deviceName = $currentToken->name;
        $oldTokenId = $currentToken->id;

        $expiresAt = config('sanctum.expiration')
            ? now()->addMinutes(config('sanctum.expiration'))
            : null;

        $newToken = $user->createToken($deviceName, ['*'], $expiresAt);

        // Rotate: delete old token
        $currentToken->delete();

        // Update session row to point at new token, refresh activity
        \DB::table('sessions')
            ->where('user_id', $user->user_id)
            ->where('ip_address', request()->ip())
            ->update([
                'last_activity' => now()->timestamp,
            ]);

        return [
            'token_type' => 'Bearer',
            'access_token' => $newToken->plainTextToken,
            'expires_at' => $expiresAt?->toDateTimeString(),
            'refresh_expires_at' => now()
                ->addMinutes(config('sanctum.refresh_expiration', config('sanctum.expiration', 0)))
                ->toDateTimeString(),
            'user' => $this->profile($user),
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
        $this->deleteCurrentSession($user);
    }

    private function deleteCurrentSession(User $user): void
    {
        \DB::table('sessions')
            ->where('user_id', $user->user_id)
            ->where('ip_address', request()->ip())
            ->delete();
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
        $user->sessions()->delete();
    }

public function profile(User $user): array
{
    // Force fresh load — never trust stale eager-loaded relations
    $user->load(['defaultStore', 'stores']);
    
    // Clear Spatie's per-model permission cache then reload
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $user->unsetRelation('roles')->unsetRelation('permissions');
    $user->load(['roles', 'permissions']);

    $hasStoreAssignment = $user->isAdmin()
        || (int) $user->stores->count() > 0
        || ! empty($user->default_store_id);

    $shiftStart = $this->normalizeTime($user->shift_start);
    $shiftEnd   = $this->normalizeTime($user->shift_end);
    $shiftLabel = $this->buildShiftLabel($user->shift_name, $shiftStart, $shiftEnd);

    return [
        'user_id'             => $user->user_id,
        'username'            => $user->username,
        'first_name'          => $user->first_name,
        'last_name'           => $user->last_name,
        'full_name'           => $user->full_name,
        'email'               => $user->email,
        'phone'               => $user->phone,
        'role'                => $user->role,
        'is_active'           => $user->is_active,
        'is_verified'         => $user->is_verified,
        'email_verified'      => $user->hasVerifiedEmail(),
        'default_store_id'    => $user->default_store_id,
        'has_store_assignment'=> $hasStoreAssignment,
        'shift_name'          => $user->shift_name,
        'shift_start'         => $shiftStart,
        'shift_end'           => $shiftEnd,
        'shift_label'         => $shiftLabel,

        'default_store' => $user->defaultStore ? [
            'store_id'         => $user->defaultStore->store_id,
            'store_name'       => $user->defaultStore->store_name,
            'currency'         => $user->defaultStore->currency,
            'location'         => $user->defaultStore->location,
            'physical_address' => $user->defaultStore->physical_address ?? $user->defaultStore->address,
            'telephone'        => $user->defaultStore->telephone ?? $user->defaultStore->phone ?? $user->defaultStore->phone_number,
            'email_address'    => $user->defaultStore->email_address ?? $user->defaultStore->email,
        ] : null,

        'stores' => $user->stores->map(fn ($store) => [
            'store_id'         => $store->store_id,
            'store_name'       => $store->store_name,
            'currency'         => $store->currency,
            'location'         => $store->location,
            'physical_address' => $store->physical_address ?? $store->address,
            'telephone'        => $store->telephone ?? $store->phone ?? $store->phone_number,
            'email_address'    => $store->email_address ?? $store->email,
        ])->values()->all(),

        'roles'       => $user->getRoleNames()->values()->all(),
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

    public function getSessions(User $user): array
    {
        $this->pruneExpiredSessions($user);

        return $user->sessions()
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($session) => [
                'id'            => $session->id,
                'ip_address'    => $session->ip_address,
                'user_agent'    => $session->user_agent,
                'last_active'   => $session->last_activity_at->diffForHumans(),
                'last_activity' => $session->last_activity_at->toDateTimeString(),
                'expires_at'    => $session->last_activity_at
                    ->copy()
                    ->addMinutes(config('sanctum.session_lifetime', 10080))
                    ->toDateTimeString(),
                'is_current'    => $session->is_current,
            ])
            ->values()
            ->all();
    }

    public function pruneExpiredSessions(User $user): void
    {
        $lifetime = config('sanctum.session_lifetime', 10080);
        $cutoff = now()->subMinutes($lifetime)->timestamp;

        $user->sessions()
            ->where('last_activity', '<', $cutoff)
            ->delete();
    }

    public function revokeSession(User $user, string $sessionId): bool
    {
        return (bool) $user->sessions()
            ->where('id', $sessionId)
            ->delete();
    }

    public function revokeAllSessions(User $user): void
    {
        $user->sessions()->delete();
    }

    public function touchSession(User $user): void
    {
        \DB::table('sessions')
            ->where('user_id', $user->user_id)
            ->where('ip_address', request()->ip())
            ->update(['last_activity' => now()->timestamp]);
    }

    private function writeSession(User $user, ?int $tokenId = null): \stdClass
    {
        $request = request();
        $id = \Str::random(40);

        \DB::table('sessions')->insert([
            'id'             => $id,
            'user_id'        => $user->user_id,
            'ip_address'     => $request->ip(),
            'user_agent'     => substr($request->userAgent() ?? '', 0, 500),
            'payload'        => '',
            'last_activity'  => now()->timestamp,
        ]);

        return (object) ['id' => $id];
    }
}