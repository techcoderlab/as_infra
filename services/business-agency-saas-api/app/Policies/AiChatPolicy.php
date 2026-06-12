<?php

namespace App\Policies;

use App\Models\AiChat;
use App\Models\User;
use App\Services\TenantManager;

class AiChatPolicy
{
    private function checkAccess(User $user, string $permission, string $scope): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $tm = app(TenantManager::class);

        return ! is_null($user->current_tenant_id)
            && $tm->isModuleEnabled('ai_chats')
            && $user->can($permission)
            && $user->tokenCan($scope);
    }

    public function viewAny(User $user): bool
    {
        return $this->checkAccess($user, 'view ai_chats', 'ai_chats:view');
    }

    public function view(User $user, AiChat $aiChat): bool
    {
        return $user->current_tenant_id === $aiChat->tenant_id
            && $this->checkAccess($user, 'view ai_chats', 'ai_chats:view');
    }

    public function create(User $user): bool
    {
        return $this->checkAccess($user, 'write ai_chats', 'ai_chats:write');
    }

    public function update(User $user): bool
    {
        return $this->checkAccess($user, 'update ai_chats', 'ai_chats:update');
    }

    public function delete(User $user, AiChat $aiChat): bool
    {
        return $user->current_tenant_id === $aiChat->tenant_id
            && $this->checkAccess($user, 'delete ai_chats', 'ai_chats:delete');
    }
}
