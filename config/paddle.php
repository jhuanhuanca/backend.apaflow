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

    /*
    | La URL efectiva se resuelve en PaddleBillingService según prefijo de PADDLE_API_KEY:
    | test_* → sandbox-api.paddle.com | live_* → api.paddle.com
    */
    'api_base_url' => env('PADDLE_SANDBOX', true)
        ? 'https://sandbox-api.paddle.com'
        : 'https://api.paddle.com',

    /*
    | Price IDs (pri_*) preferidos. Si pones pro_* (producto), el backend resuelve el primer precio activo.
    */
    'prices' => [
        'pro_subscription' => env('PADDLE_PRICE_PRO', 'pro_01kthvvxqt8ydftnc4b6nmpvaf'),
        'document_checkout' => env('PADDLE_PRICE_DOCUMENT', 'pro_01kthvpgmh491467ga6dr2jn8t'),
    ],

    'checkout' => [
        'success_url' => env('PADDLE_CHECKOUT_SUCCESS_URL', 'https://apaflow.shop/apa-generator?pro=success'),
        'cancel_url' => env('PADDLE_CHECKOUT_CANCEL_URL', 'https://apaflow.shop/apa-generator?pro=cancel'),
    ],

];
