<?php

/**
 * CORS para SPA + Sanctum.
 * Con supports_credentials=true, Access-Control-Allow-Origin debe ser un origen explícito (no "*").
 */
$isLocal = env('APP_ENV', 'production') === 'local';

$defaultOrigins = $isLocal
    ? 'http://localhost:5173,http://127.0.0.1:5173'
    : 'https://apaflow.shop,https://www.apaflow.shop,https://admin.apaflow.shop';

$originPatterns = [
    '#^https?://localhost(:\d+)?$#',
    '#^https?://127\.0\.0\.1(:\d+)?$#',
    '#^https?://\[::1\](:\d+)?$#',
    '#^https?://([a-z0-9-]+\.)*apaflow\.shop$#',
];

if ($isLocal) {
    // Laragon / Valet / hosts locales (*.test, *.local) y Vite en LAN.
    $originPatterns[] = '#^https?://[a-z0-9.-]+\.test(:\d+)?$#';
    $originPatterns[] = '#^https?://[a-z0-9.-]+\.local(:\d+)?$#';
    $originPatterns[] = '#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#';
    $originPatterns[] = '#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#';
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', $defaultOrigins))
    ))),

    'allowed_origins_patterns' => $originPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),

];
