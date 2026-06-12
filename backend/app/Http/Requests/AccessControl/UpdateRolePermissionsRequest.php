<?php

namespace App\Http\Requests\AccessControl;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) return false;

        // Check role column OR Spatie role — whichever is set
        return $user->role === User::ROLE_ADMIN
            || $user->hasRole(User::ROLE_ADMIN, 'sanctum');
    }

    public function rules(): array
    {
        return [
            'permissions'   => ['required', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where(
                    fn($query) => $query->where('guard_name', 'sanctum')
                ),
            ],
        ];
    }
}