<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'api/*',
        'ai/*',
        'ai-chats/*/chat-stream',
        'sanctum/csrf-cookie',
    ],
    // 'allowed_origins' => ['*'], // Not works when supported_credentials is true
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://hassan.test:8080',
        'http://host.docker.internal:5173',
    ],
    'allowed_origins_patterns' => [],

    'allowed_methods' => ['*'],
    'allowed_headers' => ['*', 'ngrok-skip-browser-warning'],
    'exposed_headers' => [],
    'max_age' => 0,
    // 'supports_credentials' => false,
    // ⚠️ CRITICAL for SSE
    'supports_credentials' => true,
];
