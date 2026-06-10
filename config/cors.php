<?php

/**
 * CORS para SPA + Sanctum.
 * Con supports_credentials=true, Access-Control-Allow-Origin debe ser un origen explícito (no "*").
 */
$defaultOrigins = 'http://localhost:5173,http://127.0.0.1:5173';

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', $defaultOrigins))
    ))),

    'allowed_origins_patterns' => [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
        '#^https://([a-z0-9-]+\.)*apaflow\.shop$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),

];
