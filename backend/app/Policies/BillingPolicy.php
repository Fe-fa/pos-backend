<?php

namespace App\Policies;

use App\Models\Billing;
use App\Models\User;

class BillingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing.view') || $user->can('billing.manage');
    }

    public function view(User $user, Billing $billing): bool
    {
        return $user->can('billing.view') || $user->can('billing.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('billing.create') || $user->can('billing.manage');
    }

    public function update(User $user, Billing $billing): bool
    {
        return $user->can('billing.manage');
    }

    public function delete(User $user, Billing $billing): bool
    {
        return $user->can('billing.manage');
    }

    public function restore(User $user, Billing $billing): bool
    {
        return $user->can('billing.manage');
    }

    public function forceDelete(User $user, Billing $billing): bool
    {
        return $user->can('billing.manage');
    }
}
