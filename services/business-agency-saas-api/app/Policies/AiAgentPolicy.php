<?php

namespace App\Policies;

use App\Models\AiAgent;
use App\Models\User;
use App\Services\TenantManager;

class AiAgentPolicy
{
    private function checkAccess(User $user, string $permission, string $scope): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $tm = app(TenantManager::class);

        return ! is_null($user->current_tenant_id)
            && $tm->isModuleEnabled('ai_agents')
            && $user->can($permission)
            && $user->tokenCan($scope);
    }

    public function viewAny(User $user): bool
    {
        return $this->checkAccess($user, 'view ai_agents', 'ai_agents:view');
    }

    public function view(User $user, AiAgent $aiAgent): bool
    {
        return $user->current_tenant_id === $aiAgent->tenant_id
            && $this->checkAccess($user, 'view ai_agents', 'ai_agents:view');
    }

    public function create(User $user): bool
    {
        return $this->checkAccess($user, 'write ai_agents', 'ai_agents:write');
    }

    public function update(User $user, AiAgent $aiAgent): bool
    {
        return $user->current_tenant_id === $aiAgent->tenant_id
            && $this->checkAccess($user, 'update ai_agents', 'ai_agents:update');
    }

    public function delete(User $user, AiAgent $aiAgent): bool
    {
        return $user->current_tenant_id === $aiAgent->tenant_id
            && $this->checkAccess($user, 'delete ai_agents', 'ai_agents:delete');
    }
}
