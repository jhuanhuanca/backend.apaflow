<?php

/**
 * Configuración pública de anuncios (AdSense Auto ads en el frontend Vue).
 */
return [
    'enabled' => filter_var(env('ADS_ENABLED', true), FILTER_VALIDATE_BOOL),

    'provider' => env('ADS_PROVIDER', 'adsense'),

    'publisher_client' => env('ADSENSE_PUBLISHER_CLIENT', 'ca-pub-2981754691104078'),

    'lazy_load' => filter_var(env('ADS_LAZY_LOAD', true), FILTER_VALIDATE_BOOL),

    'blocked_paths' => [
        '/login',
        '/register',
        '/registro/checkout',
        '/apa-generator',
        '/app/configuracion-apa',
    ],
];
