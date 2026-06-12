<?php

namespace App\Http\Middleware;

use App\Models\ApiAuditLog;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogApiActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $response = $next($request);
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // Only log if we have a resolved tenant (prevents logging junk scans)
        $tenant = app(TenantManager::class)->getActiveTenant();

        if ($tenant) {
            // Sanitize Payload: Remove passwords or sensitive keys
            // $payload = $request->except(['password', 'password_confirmation', 'token', 'tool_configs']);
            $payload = keys_except($request->all(), ['password', 'password_confirmation', 'token', 'tool_configs']);

            // Truncate payload if too huge (e.g. base64 images)
            $payloadJson = json_encode($payload);
            if (strlen($payloadJson) > 5000) {
                $payload = ['data' => 'Payload too large to log', 'keys' => array_keys($payload)];
            }

            ApiAuditLog::create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()?->id,
                // Assuming Sanctum token ID is accessible if using auth:sanctum
                'api_key_id' => ($token = $request->user()?->currentAccessToken()) && isset($token->id) ? $token->id : null,
                'method' => $request->method(),
                'route' => $request->path(),
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payload' => $payload,
                'duration_ms' => $duration,
            ]);
        }

        return $response;
    }
}
