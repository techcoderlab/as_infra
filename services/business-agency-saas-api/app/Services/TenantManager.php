<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TenantManager
{
    private ?Tenant $tenant = null;

    private static array $cache = [];

    public function __construct()
    {
        if ($user = Auth::user()) {
            if ($user->currentTenant) {
                $this->setTenantById($user->current_tenant_id);
                // $this->tenant = static::$cache[$tenantId] ??= Tenant::find($tenantId);
            }
        }
    }

    // public function setTenant(Tenant $tenant): void
    // {
    //     $this->tenant = $tenant;
    // }

    public function setTenantById(int $tenantId): void
    {
        // Use once() or a static cache to prevent querying the tenant table multiple times per request
        $this->tenant = static::$cache[$tenantId] ??= Tenant::find($tenantId);

        if (! $this->tenant) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Tenant {$tenantId} not found.");
        }
    }

    /**
     * Retrieve the active tenant, loading it if necessary.
     */
    // public function getTenant()
    // {
    //     if (!$this->tenant) {
    //         $user = Auth::user();

    //         // Validate user and their context
    //         if ($user && $user->current_tenant_id) {
    //             // Eager load plans and modules to optimize performance
    //             $this->tenant = Tenant::with('plans.modules')->find($user->current_tenant_id);
    //         }
    //     }
    //     return $this->tenant;
    // }

    /**
     * Check if a specific module is enabled for the active tenant.
     */
    public function isModuleEnabled(string $moduleSlug): bool
    {
        // FIX: Call getActiveTenant() to ensure it is loaded
        $tenant = $this->getActiveTenant();

        if (! $tenant) {
            return false;
        }

        $enabledModules = $this->getEnabledModules();

        return in_array($moduleSlug, $enabledModules);
    }

    /**
     * Get a list of all enabled module slugs.
     */
    // public function getEnabledModules()
    // {
    //     // FIX: Call getTenant() here too for safety
    //     $tenant = $this->getTenant();

    //     if (!$tenant || !$tenant->plans->first()) {
    //         return [];
    //     }
    //     // Cache the enabled modules for the tenant to reduce DB queries
    //     // return Cache::remember("tenant:{$tenant->id}:modules", 500, function () use ($tenant) {
    //     // A tenant can have multiple plans, so we get modules from all plans.
    //     // return $this->tenant->plans()->with('modules')->get()
    //     //     ->pluck('modules')->flatten()->pluck('slug')->unique()->toArray();
    //     return $tenant->plans->first()->modules->pluck('slug')->toArray();
    //     // });
    // }

    // public function forgetTenantCache(): void
    // {
    //     if ($this->tenant) {
    //         Cache::forget("tenant:{$this->tenant->id}:modules");
    //     }
    // }

    /**
     * Get the active tenant for the current request context.
     */
    public function getActiveTenant(): ?Tenant
    {
        if ($this->tenant) {
            return $this->tenant;
        }

        $user = Auth::user();
        if ($user && $user->current_tenant_id) {
            $this->tenant = Tenant::find($user->current_tenant_id);
        }

        return $this->tenant;
    }

    /**
     * Get enabled modules for the current tenant with caching.
     */
    public function getEnabledModules(): array
    {
        $tenant = $this->getActiveTenant();
        if (! $tenant) {
            return [];
        }

        $cacheKey = "tenant_{$tenant->id}_modules";

        return Cache::store('file')->remember($cacheKey, 3600, function () use ($tenant) {
            // Fetch modules through the plan relationship
            return DB::table('modules')
                ->join('module_plan', 'modules.id', '=', 'module_plan.module_id')
                ->join('plan_tenant', 'module_plan.plan_id', '=', 'plan_tenant.plan_id')
                ->where('plan_tenant.tenant_id', $tenant->id)
                ->pluck('slug')
                ->toArray();
        });
    }

    /**
     * Check if the tenant has reached a specific plan limit.
     *
     * * @param string $moduleSlug The slug of the module (e.g., 'crm')
     * @param  string  $modelClass  The model to count (e.g., App\Models\Lead)
     */
    public function checkLimit(string $moduleSlug, string $modelClass): bool
    {
        $tenant = $this->getActiveTenant();
        if (! $tenant) {
            return false;
        }

        // 1. Get the limit defined in the module_plan pivot
        $limit = DB::table('module_plan')
            ->join('modules', 'modules.id', '=', 'module_plan.module_id')
            ->join('plan_tenant', 'module_plan.plan_id', '=', 'plan_tenant.plan_id')
            ->where('plan_tenant.tenant_id', $tenant->id)
            ->where('modules.slug', $moduleSlug)
            ->value('limit'); // Assumes 'limit' column exists in module_plan

        // 2. If limit is null or -1, treat as unlimited
        if ($limit === null || $limit === -1) {
            return true;
        }

        // 3. Count current usage (scoped to tenant via BelongsToTenant trait)
        $currentCount = $modelClass::count();

        return $currentCount < $limit;
    }

    /**
     * Dynamic Permission Caching for the User-Tenant context.
     */
    public function getUserPermissions(User $user): array
    {
        $tenantId = $this->getActiveTenant()?->id ?? 0;
        $cacheKey = "user_{$user->id}_tenant_{$tenantId}_permissions";

        return Cache::store('file')->remember($cacheKey, 3600, function () use ($user, $tenantId) {
            // Fetch roles/permissions specific to this tenant from the tenant_user pivot
            $pivot = DB::table('tenant_user')
                ->where('user_id', $user->id)
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $pivot) {
                return [];
            }

            // Return permissions based on the role stored in the pivot
            // This avoids loading all permissions into memory every time
            return $user->getPermissionsViaRoles()->pluck('name')->toArray();
        });
    }

    /**
     * CLEAR CACHE (The Fix)
     * Call this whenever a Plan, Module, or Role is updated.
     */
    public function clearTenantCache(int $tenantId): void
    {
        // 1. Clear the module list for this tenant
        Cache::store('file')->forget("tenant_{$tenantId}_modules");

        // 2. Find all users of this tenant and clear their permission cache
        //    (Because plan limits might affect what they can see/do)
        $userIds = DB::table('tenant_user')->where('tenant_id', $tenantId)->pluck('user_id');

        foreach ($userIds as $uid) {
            Cache::store('file')->forget("user_{$uid}_tenant_{$tenantId}_permissions");
        }

        Cache::forget("tenant_{$tenantId}_ai_limit");
    }

    public function clearUserCache(int $userId, int $tenantId): void
    {
        Cache::store('file')->forget("user_{$userId}_tenant_{$tenantId}_permissions");
    }

    // public function clearCache(int $userId, int $tenantId): void
    // {
    //     Cache::forget("user_{$userId}_tenant_{$tenantId}_permissions");
    //     Cache::forget("tenant_{$tenantId}_modules");
    // }
}
