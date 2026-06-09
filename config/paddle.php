<?php

/**
 * Paddle Billing (API v2).
 * Documentación: https://developer.paddle.com/
 */
return [

    'enabled' => filter_var(env('PADDLE_ENABLED', true), FILTER_VALIDATE_BOOL),

    'sandbox' => filter_var(env('PADDLE_SANDBOX', true), FILTER_VALIDATE_BOOL),

    'api_key' => env('PADDLE_API_KEY'),

    'client_token' => env('PADDLE_CLIENT_TOKEN'),

    'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),

    'api_base_url' => env('PADDLE_SANDBOX', true)
        ? 'https://sandbox-api.paddle.com'
        : 'https://api.paddle.com',

    /*
    | Price IDs de catálogo Paddle (Billing).
    */
    'prices' => [
        'pro_subscription' => env('PADDLE_PRICE_PRO', 'pro_01kthvvxqt8ydftnc4b6nmpvaf'),
        'document_checkout' => env('PADDLE_PRICE_DOCUMENT', 'pro_01kthvpgmh491467ga6dr2jn8t'),
    ],

];
