<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Services\TenantManager;

class LeadPolicy
{
    /**
     * Helper to check Module + Permissions + Token Scope
     */
    private function checkAccess(User $user, string $permission, string $scope): bool
    {
        // 1. Super Admins bypass everything
        if ($user->isSuperAdmin()) {
            return true;
        }

        $tm = app(TenantManager::class);

        // 2. Check Context (Must be in a tenant)
        if (is_null($user->current_tenant_id)) {
            return false;
        }

        // 3. Check if 'Leads' module is enabled for this tenant
        if (! $tm->isModuleEnabled('leads')) {
            return false;
        }

        // 4. Check User Permission & API Token Scope
        return $user->can($permission) && $user->tokenCan($scope);
    }

    public function viewAny(User $user): bool
    {
        return $this->checkAccess($user, 'view leads', 'leads:view');
    }

    public function view(User $user, Lead $lead): bool
    {
        // REMOVED: $user->current_tenant_id === $lead->tenant_id
        // WHY: The Global "Landlord Gate" in AppServiceProvider does this automatically!
        return $this->checkAccess($user, 'view leads', 'leads:view');
    }

    public function create(User $user): bool
    {
        // Note: Creating doesn't have a $lead instance yet, so no tenant_id check needed here
        // But you might want to check the "Plan Limit" here using TenantManager::checkLimit
        $tm = app(TenantManager::class);
        if (! $tm->checkLimit('leads', Lead::class)) {
            return false;
        }

        return $this->checkAccess($user, 'write leads', 'leads:write');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->checkAccess($user, 'update leads', 'leads:update');
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->checkAccess($user, 'delete leads', 'leads:delete');
    }
}

// namespace App\Policies;

// use App\Models\Lead;
// use App\Models\User;
// use App\Services\TenantManager;

// class LeadPolicy
// {
//     private function checkAccess(User $user, string $permission, string $scope): bool
//     {
//         if ($user->isSuperAdmin()) return true;

//         $tm = app(TenantManager::class);

//         return !is_null($user->current_tenant_id)
//             && $tm->isModuleEnabled('leads')
//             && $user->can($permission)
//             && $user->tokenCan($scope);
//     }

//     public function viewAny(User $user): bool
//     {
//         return $this->checkAccess($user, 'view leads', 'leads:view');
//     }

//     public function view(User $user, Lead $lead): bool
//     {
//         return $user->current_tenant_id === $lead->tenant_id
//             && $this->checkAccess($user, 'view leads', 'leads:view');
//     }

//     public function create(User $user): bool
//     {
//         return $this->checkAccess($user, 'write leads', 'leads:write');
//     }

//     public function update(User $user, Lead $lead): bool
//     {
//         return $user->current_tenant_id === $lead->tenant_id
//             && $this->checkAccess($user, 'update leads', 'leads:update');
//     }

//     public function delete(User $user, Lead $lead): bool
//     {
//         return $user->current_tenant_id === $lead->tenant_id
//             && $this->checkAccess($user, 'delete leads', 'leads:delete');
//     }
// }
