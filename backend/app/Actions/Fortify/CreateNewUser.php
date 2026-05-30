<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Rules\Password;

class CreateNewUser implements CreatesNewUsers
{
    public function create(array $input): User
    {
        $firstName = trim($input['first_name'] ?? '');
        $lastName = trim($input['last_name'] ?? '');

        if (($firstName === '' || $lastName === '') && !empty($input['full_name'])) {
            [$firstName, $lastName] = $this->splitFullName($input['full_name']);
        }

        Validator::make(
            array_merge($input, [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]),
            [
                'first_name' => ['required', 'string', 'max:50'],
                'last_name' => ['required', 'string', 'max:50'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
                'username' => [
                    'nullable',
                    'string',
                    'max:50',
                    'regex:/^[A-Za-z0-9._-]+$/',
                    Rule::unique('users', 'username'),
                ],
                'phone' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
                'password' => ['required', 'string', new Password, 'confirmed'],
                'default_store_id' => ['nullable', 'integer', 'exists:stores,store_id'],
            ],
            [
                'username.regex' => 'The username may only contain letters, numbers, dots, dashes, and underscores.',
                'phone.regex' => 'Phone number must be 10–15 digits and may start with +.',
            ]
        )->validate();

        return DB::transaction(function () use ($input, $firstName, $lastName) {
            $username = !empty($input['username'])
                ? $input['username']
                : $this->generateUsername($firstName, $lastName);

            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => $username,
                'email' => $input['email'],
                'phone' => $input['phone'] ?? null,
                'password' => $input['password'],
                'role' => User::ROLE_CASHIER,
                'default_store_id' => $input['default_store_id'] ?? null,
                'is_active' => true,
                'is_verified' => false,
            ]);

            $user->assignRole(User::ROLE_CASHIER);

            return $user;
        });
    }

    private function generateUsername(string $firstName, string $lastName): string
    {
        $base = Str::slug(trim($firstName . '_' . $lastName), '_') ?: 'user';
        $base = Str::limit($base, 40, '');
        $username = $base;
        $count = 1;

        while (User::where('username', $username)->exists()) {
            $suffix = '_' . $count++;
            $username = Str::limit($base, 50 - strlen($suffix), '') . $suffix;
        }

        return $username;
    }

    private function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? 'User';

        return [$firstName, $lastName];
    }
}
