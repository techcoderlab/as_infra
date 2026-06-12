<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantStatus
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $user->tenant;

        if ($user && $tenant && $tenant->status === 'suspended') {
            return response()->json([
                'message' => 'Your tenant account temporarily deactivated.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Triggers the 'access-tenant' gate defined above
        // $tenantId = $request->route('tenant'); // ID from URL
        // if (! Gate::allows('access-tenant', $tenantId)) {
        //     return response()->json([
        //         'message' => 'You do not have access to this tenant.',
        //     ], Response::HTTP_FORBIDDEN);
        // }

        return $next($request);
    }
}
