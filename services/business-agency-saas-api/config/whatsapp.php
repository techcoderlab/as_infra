<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default WhatsApp Driver
    |--------------------------------------------------------------------------
    |
    | This option determines the default WhatsApp driver that is utilized for
    | incoming and outgoing WhatsApp messages.
    |
    */

    'base_url' => env('GRAPH_API_BASE_URL', 'https://graph.facebook.com'),

    'graph_api_version' => env('GRAPH_API_VERSION', 'v24.0'),

    'whatsapp_per_minute_limit' => env('WHATSAPP_PER_MINUTE_LIMIT', 20),

];
