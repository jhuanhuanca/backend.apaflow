<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /**
     * Microservicio FastAPI de formateo APA 7 (python-docx).
     */
    'python' => [
        'url' => env('PYTHON_SERVICE_URL', 'http://127.0.0.1:8001'),
        'timeout' => (int) env('PYTHON_SERVICE_TIMEOUT', 300),
        'api_key' => env('PYTHON_SERVICE_API_KEY'),
    ],

];
