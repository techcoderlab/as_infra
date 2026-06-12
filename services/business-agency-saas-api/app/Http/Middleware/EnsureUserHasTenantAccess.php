<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EnsureUserHasTenantAccess
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Get the tenant ID from the header or route parameter
        $targetTenantId = $request->header('X-Tenant-Id') ?? $request->route('tenant_id');

        if ($targetTenantId) {
            // 2. USE THE GATE HERE
            if (! Gate::allows('access-tenant', $targetTenantId)) {
                abort(403, 'You do not have access to this tenant.');
            }

            // 3. Set the context if the gate passes
            $user = auth()->user();
            if ($user && $user->current_tenant_id !== $targetTenantId && method_exists($user, 'update')) {
                $user->update(['current_tenant_id' => $targetTenantId]);
            }
        }

        return $next($request);
    }
}
