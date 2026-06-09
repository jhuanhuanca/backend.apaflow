<?php

/**
 * Catálogo de pasarelas y canales de cobro (tarjeta vs QR / billetera).
 * La app usa esto para validar altas manuales, mostrar opciones al usuario y comprobar si hay credenciales en .env.
 */
return [

    /*
    | Checkout simulado post-registro + APIs protegidas hasta completarlo.
    | Si PAYMENT_DEMO_UPGRADE no está definido o está vacío, se usa APP_DEBUG.
    | En producción: PAYMENT_DEMO_UPGRADE=false desactiva el gate (los nuevos usuarios se marcan checkout al registrarse).
    */
    'demo_upgrade_enabled' => \Illuminate\Support\Str::of((string) env('PAYMENT_DEMO_UPGRADE'))->trim()->isEmpty()
        ? (bool) env('APP_DEBUG', false)
        : filter_var(env('PAYMENT_DEMO_UPGRADE'), FILTER_VALIDATE_BOOLEAN),

    /*
    | auto => Paddle si hay credenciales, si no demo (si está habilitado).
    | paddle | demo
    */
    'provider' => env('PAYMENT_PROVIDER', 'auto'),

    'gateways' => [
        'demo' => [
            'label' => 'Demostración (sin cargo real)',
            'description' => 'Solo para pruebas: activa Plan Pro y registra un pago ficticio.',
            'channels' => ['card', 'qr'],
            'env' => null,
        ],
        'stripe' => [
            'label' => 'Stripe',
            'description' => 'Tarjetas internacionales; QR / Payment Links según región y producto.',
            'channels' => ['card', 'qr'],
            'env' => 'STRIPE_SECRET',
        ],
        'mercadopago' => [
            'label' => 'Mercado Pago',
            'description' => 'Latinoamérica: tarjeta, efectivo vía referencia y pagos con QR / billetera.',
            'channels' => ['card', 'qr'],
            'env' => 'MERCADOPAGO_ACCESS_TOKEN',
        ],
        'paypal' => [
            'label' => 'PayPal',
            'description' => 'PayPal estándar y PayPal Checkout (tarjeta invitado); algunos flujos admiten QR vía invoice.',
            'channels' => ['card', 'qr'],
            'env' => 'PAYPAL_CLIENT_SECRET',
        ],
        'payu_latam' => [
            'label' => 'PayU Latam',
            'description' => 'Tarjeta y medios locales (PSE, OXXO, etc.); muchos países admiten QR vía app bancaria.',
            'channels' => ['card', 'qr'],
            'env' => 'PAYU_API_KEY',
        ],
        'manual' => [
            'label' => 'Registro manual / transferencia',
            'description' => 'Sin pasarela en línea: admin marca el pago al verificar transferencia o comprobante.',
            'channels' => ['card', 'qr'],
            'env' => null,
        ],
        'paddle' => [
            'label' => 'Paddle Billing',
            'description' => 'Checkout seguro Paddle para suscripción Pro y pago por documento.',
            'channels' => ['card'],
            'env' => 'PADDLE_API_KEY',
        ],
    ],

    /*
    | Etiquetas fijas para el canal de cobro (además del proveedor).
    */
    'channel_labels' => [
        'card' => 'Tarjeta (crédito / débito)',
        'qr' => 'QR / billetera / código',
    ],
];
