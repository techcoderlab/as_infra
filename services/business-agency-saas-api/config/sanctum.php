<?php

use Laravel\Sanctum\Sanctum;

return [
    /*
    |--------------------------------------------------------------------------
    | Sanctum Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, this should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an access token issued
    | by this application is considered expired. If this value is null,
    | personal access tokens will not expire unless revoked manually.
    |
    */

    'expiration' => env('SANCTUM_EXPIRATION'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of token
    | scanning abilities provided by applications like GitHub Advanced
    | Security. You may define a prefix for your application's tokens.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        'verify_csrf_token' => Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ],

    'allowed_scopes' => [
        'leads:view',
        'leads:write',
        'leads:update',
        'leads:delete',
        'forms:view',
        'forms:write',
        'forms:update',
        'forms:delete',
        'webhooks:view',
        'webhooks:write',
        'webhooks:update',
        'webhooks:delete',
        'ai_chats:view',
        'ai_chats:write',
        'ai_chats:update',
        'ai_chats:delete',
        'api_keys:view',
        'api_keys:write',
        'api_keys:update',
        'api_keys:delete',
        'ai_agents:view',
        'ai_agents:write',
        'ai_agents:update',
        'ai_agents:delete',

    ],
];
