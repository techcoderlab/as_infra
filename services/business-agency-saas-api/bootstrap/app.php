<?php

use App\Http\Middleware\CheckTenantModule;
use App\Http\Middleware\CheckTenantStatus;
use App\Http\Middleware\EnsureUserHasTenantAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // $middleware->append(\App\Http\Middleware\NgrokMiddleware::class);

        // Trust all proxies for ngrok/local development
        // $middleware->trustProxies(at: '*');

        $middleware->appendToGroup('api', HandleCors::class);
        $middleware->alias([
            // Register the Tenant Module Gate
            'check.module' => CheckTenantModule::class,
            'check.status' => CheckTenantStatus::class,
            'check.tenant_access' => EnsureUserHasTenantAccess::class,

            'plan.expiry' => \App\Http\Middleware\CheckPlanExpiry::class,
            'log.api' => \App\Http\Middleware\LogApiActivity::class,
            'tracker.validate' => \App\Http\Middleware\ValidateTrackerPublicFormRequest::class,

            // Ensure Spatie middleware is aliased if you use it directly in routes
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
