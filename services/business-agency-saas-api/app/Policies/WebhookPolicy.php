<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;
use App\Services\TenantManager;

class WebhookPolicy
{
    private function checkAccess(User $user, string $permission, string $scope): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $tm = app(TenantManager::class);

        return ! is_null($user->current_tenant_id)
            && $tm->isModuleEnabled('webhooks')
            && $user->can($permission)
            && $user->tokenCan($scope);
    }

    public function viewAny(User $user): bool
    {
        return $this->checkAccess($user, 'view webhooks', 'webhooks:view');
    }

    public function view(User $user, Webhook $webhook): bool
    {
        return $user->current_tenant_id === $webhook->tenant_id
            && $this->checkAccess($user, 'view webhooks', 'webhooks:view');
    }

    public function create(User $user): bool
    {
        return $this->checkAccess($user, 'write webhooks', 'webhooks:write');
    }

    public function update(User $user, Webhook $webhook): bool
    {
        return $user->current_tenant_id === $webhook->tenant_id
            && $this->checkAccess($user, 'update webhooks', 'webhooks:update');
    }

    public function delete(User $user, Webhook $webhook): bool
    {
        return $user->current_tenant_id === $webhook->tenant_id
            && $this->checkAccess($user, 'delete webhooks', 'webhooks:delete');
    }
}
