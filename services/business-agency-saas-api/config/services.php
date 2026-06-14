<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mcp_sidecar' => [
        'calling_api_name' => env('MCP_SIDECAR_CALLING_API_NAME', 'agency-saas-api-prod'),
        'url' => env('MCP_SIDECAR_URL', 'http://localhost:3000'),
        // 'token' => env('MCP_SERVICE_TOKEN'),
        'client_app_id' => env('MCP_SIDECARD_CLIENT_APP_ID'),
        'client_secret' => env('MCP_SIDECARD_CLIENT_SECRET'),
        'global_concurrency' => (int) env('AI_GLOBAL_CONCURRENCY', 2),
        'tenant_concurrency' => (int) env('AI_TENANT_CONCURRENCY', 1),
        'tenant_rate_limit' => (int) env('AI_TENANT_RATE_LIMIT', 10),
        'job_timeout' => (int) env('AI_SIDECAR_TIMEOUT', 300), // 300 = 5mins
        'webhook_base_url' => env('APP_URL', 'http://gateway:80'),
    ],

    'google_business' => [
        'client_id' => env('GOOGLE_BUSINESS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_BUSINESS_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_BUSINESS_REDIRECT_URI'),
    ],

];
