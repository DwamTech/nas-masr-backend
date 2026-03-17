<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure CORS settings for your application. This
    | configuration is used by the CORS middleware to determine what
    | cross-origin requests should be allowed.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:8000',
        'http://192.168.1.35:3000',
        'http://172.29.0.1:3000',      // ✅ Docker Frontend IP
        'http://192.168.1.60:3001',
        'http://192.168.1.15:3000',
        'http://localhost:3000',
        'https://www.nasmasr.app',
        'https://nasmasr.app',
    ],

    'allowed_origins_patterns' => [
        // '#^https://.*\.example\.com$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Authorization',
        'X-Total-Count',
        'X-Page-Count',
    ],

    'max_age' => 0,

    'supports_credentials' => true,
];
