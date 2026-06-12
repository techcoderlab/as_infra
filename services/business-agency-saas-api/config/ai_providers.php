<?php

return [
    'services' => [
        'openai' => [
            'name' => 'OpenAI',
            'enabled' => true,
            // I added a public CDN path here for instant visualization
            'logo' => 'https://cdn.simpleicons.org/openaigym/000000/ffffff',
            'fields' => [
                ['name' => 'api_key', 'type' => 'password', 'label' => 'API Key', 'required' => true],
                // ['name' => 'organization_id', 'type' => 'text', 'label' => 'Organization ID (Optional)', 'required' => false],
            ],
            'default_model' => 'gpt-4-turbo',
        ],
        'gemini' => [
            'name' => 'Google Gemini',
            'enabled' => true,
            // Google Gemini Logo
            'logo' => 'https://cdn.simpleicons.org/googlegemini/4E86F8',
            'fields' => [
                ['name' => 'api_key', 'type' => 'password', 'label' => 'API Key', 'required' => true],
            ],
            'default_model' => 'gemini-2.0-flash',
        ],
        'anthropic' => [
            'name' => 'Anthropic',
            'enabled' => true,
            'logo' => 'https://cdn.simpleicons.org/anthropic/000000/ffffff',
            'fields' => [
                ['name' => 'api_key', 'type' => 'password', 'label' => 'API Key', 'required' => true],
            ],
            // 'default_model' => 'claude-3-5-sonnet-20240620',
            'default_model' => 'claude-haiku-3-5-20240620',
        ],
        'whatsapp' => [
            'name' => 'WhatsApp', // This is not an AI provider, but a tool
            'enabled' => true,
            'logo' => 'https://cdn.simpleicons.org/whatsapp/25D366',
            'fields' => [
                ['name' => 'api_key', 'type' => 'password', 'label' => 'API Key', 'required' => true],
                ['name' => 'phone_id', 'type' => 'text', 'label' => 'Phone ID', 'required' => true],
                ['name' => 'verify_token', 'type' => 'text', 'label' => 'Verify Token', 'required' => false],
                ['name' => 'app_secret', 'type' => 'text', 'label' => 'App Secret', 'required' => false],
            ],
        ],
        'gmail' => [
            'name' => 'Gmail',
            'enabled' => true,
            'logo' => 'https://cdn.simpleicons.org/gmail/EA4335',
            'fields' => [
                ['name' => 'api_key', 'type' => 'password', 'label' => 'API Key', 'required' => true],
            ],
        ],
        'googlesheets' => [
            'name' => 'Google Sheets',
            'enabled' => true,
            'logo' => 'https://cdn.simpleicons.org/googlesheets/00ff00',
            'fields' => [

                ['name' => 'type', 'type' => 'hidden', 'label' => 'Auth Type', 'value' => 'service_account'],
                ['name' => 'project_id', 'type' => 'text', 'label' => 'Project ID', 'required' => true],
                ['name' => 'private_key_id', 'type' => 'text', 'label' => 'Private Key ID', 'required' => true],
                ['name' => 'private_key', 'type' => 'textarea', 'label' => 'Private Key', 'required' => true, 'rows' => 10, 'cols' => 50],
                ['name' => 'client_email', 'type' => 'text', 'label' => 'Client Email', 'required' => true],
                ['name' => 'client_id', 'type' => 'text', 'label' => 'Client ID', 'required' => true],
                ['name' => 'auth_uri', 'type' => 'text', 'label' => 'Auth URI', 'required' => true],
                ['name' => 'token_uri', 'type' => 'text', 'label' => 'Token URI', 'required' => true],
                // ['name' => 'auth_provider_x509_cert_url', 'type' => 'text', 'label' => "Auth Provider X509 Cert URL", 'required' => true],
                // ['name' => 'client_x509_cert_url', 'type' => 'text', 'label' => "Client X509 Cert URL", 'required' => true],
                // ['name' => 'universe_domain', 'type' => 'hidden', 'label' => "Universe Domain", 'required' => true],
            ],
        ],
        'google_business' => [
            'name' => 'Google Business Profile',
            'enabled' => true,
            'logo' => 'https://cdn.simpleicons.org/google/4285F4',
            'fields' => [
                ['name' => 'connect', 'type' => 'oauth', 'label' => 'Connect Account', 'required' => true],
            ],
        ],
    ],
];
