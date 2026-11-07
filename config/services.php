<?php

return [
    'keycloak' => [
        'base_url' => env('KEYCLOAK_BASE_URL', 'http://localhost:8080'),
        'realms' => env('KEYCLOAK_REALM', 'infocell'),
        'client_id' => env('KEYCLOAK_CLIENT_ID', 'infocell-web'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'admin_client_id' => env('KEYCLOAK_ADMIN_CLIENT_ID', 'infocell-api'),
        'admin_client_secret' => env('KEYCLOAK_ADMIN_CLIENT_SECRET'),
        'redirect' => env('KEYCLOAK_REDIRECT_URI', env('APP_URL') ? rtrim(env('APP_URL'), '/').'/auth/callback' : 'http://localhost:8000/auth/callback'),
    ],


    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
