<?php

/**
 * Configuración compartida entre clientes (SPA Vue, app Flutter, futuros).
 * Expuesta en GET /api/config — no incluir secretos.
 */
return [

    'brand' => [
        'name' => env('SAAS_BRAND_NAME', 'APA Flow'),
        'tagline' => env('SAAS_BRAND_TAGLINE', 'Convierte tu Word a formato APA 7'),
    ],

    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'https://apaflow.shop'), '/'),

    /*
    | Límites modo invitado (sin cuenta). Deben coincidir con la lógica de paywall en clientes.
    */
    'guest_max_uploads' => (int) env('GUEST_MAX_UPLOADS', 1),
    'guest_max_free_downloads' => (int) env('GUEST_MAX_FREE_DOWNLOADS', 1),

    'upload' => [
        'max_file_kb' => (int) env('UPLOAD_MAX_FILE_KB', 51200),
        'allowed_mimes' => ['docx'],
    ],

    'defaults' => [
        'university' => 'General',
        'career' => 'Formateo APA 7',
    ],

    'pricing' => [
        'currency' => 'USD',
        'free_per_document_cents' => (int) env('PRICE_PER_DOCUMENT_CENTS', 99),
        'free_per_document_formatted' => env('PRICE_PER_DOCUMENT_FORMATTED', '$0.99'),
        'pro_monthly_cents' => (int) env('PRO_MONTHLY_CENTS', 599),
        'pro_monthly_formatted' => env('PRO_MONTHLY_FORMATTED', '$5.99'),
    ],

    'subscription' => [
        'pro_duration_days' => (int) env('PRO_SUBSCRIPTION_DURATION_DAYS', 30),
        /** Al vencer PRO: borrar documentos, logs, archivos y apa_settings (conserva cuenta y pagos). */
        'purge_history_on_expiry' => filter_var(env('PRO_PURGE_HISTORY_ON_EXPIRY', true), FILTER_VALIDATE_BOOL),
    ],

    'plans' => [
        'free' => [
            'requires_payment_per_document' => true,
            'unlimited_documents' => false,
            'can_customize_apa' => false,
            /** Historial de documentos FREE: se eliminan tras N días. */
            'document_retention_days' => (int) env('FREE_DOCUMENT_RETENTION_DAYS', 30),
            'document_retention_enabled' => filter_var(env('FREE_DOCUMENT_RETENTION_ENABLED', true), FILTER_VALIDATE_BOOL),
        ],
        'pro' => [
            'requires_payment_per_document' => false,
            'unlimited_documents' => true,
            'can_customize_apa' => true,
        ],
    ],

    'apa_defaults' => [
        'margins_in' => [
            'top' => 1.0,
            'bottom' => 1.0,
            'left' => 1.0,
            'right' => 1.0,
        ],
        'line_spacing' => 2.0,
        'font' => 'Times New Roman',
        'font_size_pt' => 12,
        'title_case' => 'title',
        'page_number_position' => 'header_top_right',
        'heading_levels' => [
            'h1' => ['bold' => true, 'centered' => true],
            'h2' => ['bold' => true, 'left' => true],
            'h3' => ['bold' => true, 'italic' => true, 'left' => true],
        ],
    ],

];
