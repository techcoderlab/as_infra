<?php

namespace App\Http\Middleware;

use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantModule
{
    protected $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    /**
     * Gate 1: Tenant Module Check
     */
    public function handle(Request $request, Closure $next, string $moduleSlug): Response
    {
        // 1. Check if module is enabled in the plan
        if (! $this->tenantManager->isModuleEnabled($moduleSlug)) {
            return response()->json(['message' => "The {$moduleSlug} module is not included in your current plan."], 403);
        }

        return $next($request);
    }
}
