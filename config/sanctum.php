<?php

$defaultStateful = 'localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,::1';

$stateful = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', $defaultStateful))
)));

if (env('APP_ENV', 'production') === 'local') {
    $stateful = array_values(array_unique(array_merge($stateful, [
        '*.test',
        '*.test:*',
        '*.local',
        '*.local:*',
    ])));
}

return [

    'stateful' => $stateful,

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => \App\Http\Middleware\ValidateCsrfToken::class,
    ],

];
