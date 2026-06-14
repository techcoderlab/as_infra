<?php

namespace App\Providers;

use App\Models\Lead;
use App\Observers\LeadObserver;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Providers\McpSidecarAdapter;
use App\Services\TenantManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantManager::class, function ($app) {
            return new TenantManager;
        });
        $this->app->bind(LlmProviderInterface::class, function ($app) {
            return new McpSidecarAdapter(
                baseUrl: config('services.mcp_sidecar.url'),
                client_app_id: config('services.mcp_sidecar.client_app_id'),
                client_secret: config('services.mcp_sidecar.client_secret')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /* Force https if ngrok is used */
        if (str_contains(config('app.url'), 'ngrok-free.app')) {
            \URL::forceScheme('https');
        }

        Lead::observe(LeadObserver::class);

        Event::listen(
            [
                \App\Events\LeadCreated::class,
                \App\Events\WhatsAppMessageReceived::class,
            ],
            \App\Listeners\AgentTriggerListener::class
        );
        // Event::listen(
        //     [
        //         \App\Events\WhatsAppMessageReceived::class
        //     ],
        //     \App\Listeners\AgentTriggerMessageReceivedListener::class
        // );

        // RateLimiter::for(config('services.mcp_sidecar.calling_api_name'), function (int $tenantId) {
        //     // Limit to 10 agents per minute per tenant.
        //     // This keeps you under most API "Free/Lower" tiers safely.
        //     return Limit::perMinute(5)->by($tenantId);
        // });

        // Define a rate limiter specifically for Tenant APIs
        RateLimiter::for('tenant_api', function (Request $request) {
            // Use X-Tenant-Id header, falling back to IP if missing (though Tenant ID should be mandatory)
            $tenantId = $request->header('X-Tenant-Id') ?: $request->user()?->tenant_id;
            $key = $tenantId ?? $request->ip();

            $responseString = [
                'message' => 'Too many requests. Please slow down your API calls.',
                'status' => 429,
            ];

            // Limit: 60 requests per minute per tenant
            return [
                Limit::perSecond(20)->by($key)->response(function () use ($responseString) {
                    return response()->json($responseString, 429);
                }),
                Limit::perMinute(120)->by($key)->response(function () use ($responseString) {
                    return response()->json($responseString, 429);
                })
            ];
        });

        // The "Landlord" Gate to prevent cross-tenant data access
        Gate::define('access-tenant', function ($user, $tenantId) {
            // Prevent access if the user is not associated with this tenant
            return DB::table('tenant_user')
                ->where('user_id', $user->id)
                ->where('tenant_id', $tenantId)
                ->exists();
        });

        // Global check for any model that uses BelongsToTenant
        Gate::after(function ($user, $ability, $result, $arguments) {
            if ($result === false) {
                return false;
            }

            foreach ($arguments as $argument) {
                if (is_object($argument) && isset($argument->tenant_id)) {
                    if ($argument->tenant_id !== $user->current_tenant_id) {
                        return false;
                    }
                }
            }
        });

        // Gate::define('access-tenant-resource', function ($user, $model) {
        //     if ($user->isSuperAdmin()) {
        //         return true;
        //     }

        //     $tenantManager = app(TenantManager::class);
        //     if (!$tenantManager->getTenant()) {
        //         return false;
        //     }

        //     // Check if the model uses the BelongsToTenant trait
        //     if (in_array(\App\Traits\BelongsToTenant::class, class_uses_recursive($model))) {
        //         return $model->tenant_id === $tenantManager->getTenant()->id;
        //     }

        //     return false; // Or true, depending on default behavior for non-tenant models
        // });

        /* Gates to define permissioning for API Keys Module */
        $tenantManager = app(TenantManager::class);

        Gate::define('api_keys.view', function ($user) use ($tenantManager) {
            return $user->isNotSuperAdmin() && !$user->isTenantStaff() && $tenantManager->isModuleEnabled('api_keys') && $user->can('view api_keys');
        });

        Gate::define('api_keys.write', function ($user) use ($tenantManager) {
            return $user->isNotSuperAdmin() && !$user->isTenantStaff() && $tenantManager->isModuleEnabled('api_keys') && $user->can('write api_keys');
        });

        Gate::define('api_keys.update', function ($user) use ($tenantManager) {
            return $user->isNotSuperAdmin() && !$user->isTenantStaff() && $tenantManager->isModuleEnabled('api_keys') && $user->can('update api_keys');
        });

        Gate::define('api_keys.delete', function ($user) use ($tenantManager) {
            return $user->isNotSuperAdmin() && !$user->isTenantStaff() && $tenantManager->isModuleEnabled('api_keys') && $user->can('delete api_keys');
        });
    }
}
