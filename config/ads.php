<?php

/**
 * Configuración pública de anuncios (sin secretos de AdSense/AdMob).
 * IDs de unidades se exponen solo si son públicos (client-side).
 */
return [
    'enabled' => filter_var(env('ADS_ENABLED', true), FILTER_VALIDATE_BOOL),

    'provider' => env('ADS_PROVIDER', 'placeholder'),

    'lazy_load' => filter_var(env('ADS_LAZY_LOAD', true), FILTER_VALIDATE_BOOL),

    /*
    | Slots nombrados — reemplazar con IDs reales de AdSense/AdMob en producción.
    | Ejemplo AdSense: ADS_SLOT_HOME_TOP=ca-pub-xxx/yyy
    */
    'slots' => [
        'home_top' => env('ADS_SLOT_HOME_TOP', ''),
        'home_mid' => env('ADS_SLOT_HOME_MID', ''),
        'home_pre_footer' => env('ADS_SLOT_HOME_PRE_FOOTER', ''),
        'guide_top' => env('ADS_SLOT_GUIDE_TOP', ''),
        'guide_inline' => env('ADS_SLOT_GUIDE_INLINE', ''),
        'guide_bottom' => env('ADS_SLOT_GUIDE_BOTTOM', ''),
        'guide_sidebar' => env('ADS_SLOT_GUIDE_SIDEBAR', env('ADS_SLOT_SIDEBAR', '')),
    'sidebar' => env('ADS_SLOT_SIDEBAR', ''),
        'footer' => env('ADS_SLOT_FOOTER', ''),
        'dashboard' => env('ADS_SLOT_DASHBOARD', ''),
    ],

    'inline_interval' => (int) env('ADS_INLINE_INTERVAL', 3),

    'blocked_paths' => [
        '/login',
        '/register',
        '/registro/checkout',
        '/apa-generator',
        '/app/configuracion-apa',
    ],
];
