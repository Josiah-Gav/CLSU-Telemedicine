<?php

return [

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

    /*
    | Jitsi as a Service (JaaS / 8x8) — video consultations.
    | JWTs are RS256-signed server-side; see https://developer.8x8.com/jaas/docs/api-keys-jwt
    */
    'jitsi' => [
        'domain' => env('JITSI_DOMAIN', '8x8.vc'),

        // The "vpaas-magic-cookie-..." tenant identifier. Becomes the JWT "sub" claim
        // and prefixes the IFrame API room name as "{app_id}/{room_name}".
        'app_id' => env('JITSI_APP_ID'),

        // The API Key ID from the JaaS console. Becomes the JWT header "kid".
        'api_key_id' => env('JITSI_API_KEY_ID'),

        // The RSA private key is stored on a single .env line with literal \n escapes,
        // so restore real newlines here or PEM parsing fails. This runs at config build
        // time, which keeps it correct under config:cache.
        'private_key' => str_replace('\n', "\n", (string) env('JITSI_PRIVATE_KEY')),

        // Token lifetime in seconds. Tokens are minted per join request and never stored,
        // so this only needs to outlast a single consultation. Kept short to limit the
        // blast radius of a leaked token; rejoining simply mints a fresh one.
        'jwt_ttl' => 1800,
    ],

    /*
    | CHIS (CLSU clinic health information system) REST API — patient medical
    | profiles and encounter push. See App\Services\ChisClient. Timeouts are
    | in seconds; the client never retries on its own.
    */
    'chis' => [
        'base_url' => env('CHIS_BASE_URL'),
        'token' => env('CHIS_TOKEN'),
        'timeout' => (int) env('CHIS_TIMEOUT', 5),
        'connect_timeout' => (int) env('CHIS_CONNECT_TIMEOUT', 2),
    ],

];
