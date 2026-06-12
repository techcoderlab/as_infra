<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;
use App\Services\TenantManager;

class FormPolicy
{
    private function checkAccess(User $user, string $permission, string $scope): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $tm = app(TenantManager::class);

        return ! is_null($user->current_tenant_id)
            && $tm->isModuleEnabled('forms')
            && $user->can($permission)
            && $user->tokenCan($scope);
    }

    public function viewAny(User $user): bool
    {
        return $this->checkAccess($user, 'view forms', 'forms:view');
    }

    public function view(User $user, Form $form): bool
    {
        return $user->current_tenant_id === $form->tenant_id
            && $this->checkAccess($user, 'view forms', 'forms:view');
    }

    public function create(User $user): bool
    {
        return $this->checkAccess($user, 'write forms', 'forms:write');
    }

    public function update(User $user, Form $form): bool
    {
        return $user->current_tenant_id === $form->tenant_id
            && $this->checkAccess($user, 'update forms', 'forms:update');
    }

    public function delete(User $user, Form $form): bool
    {
        return $user->current_tenant_id === $form->tenant_id
            && $this->checkAccess($user, 'delete forms', 'forms:delete');
    }
}
