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

    // ── Provider Automation API ───────────────────────────────────────────────
    // The zazy-automation container exposes a Flask API on port 8899.
    // Both containers must be on dokploy-network.
    'automation_api' => [
        'url' => env('AUTOMATION_API_URL', 'http://zazy-automation:8899'),
        'key' => env('AUTOMATION_API_KEY', ''),
    ],

    // ── Zazy Automation Configuration ─────────────────────────────────────────
    'zazy_automation' => [
        'webhook_token' => env('ZAZY_WEBHOOK_TOKEN', ''),
    ],

    // ── LayerSeven Automation Configuration ──────────────────────────────────
    'layerseven_automation' => [
        'webhook_token' => env('LAYERSEVEN_WEBHOOK_TOKEN', ''),
    ],

    // ── Ugeen Automation Configuration ────────────────────────────────────────
    'ugeen_automation' => [
        'webhook_token' => env('UGEEN_WEBHOOK_TOKEN', ''),
        'retry_buffer_minutes' => env('UGEEN_RETRY_BUFFER_MINUTES', 2),
    ],

    // ── Telegram Notifications ────────────────────────────────────────────────
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    ],

    // ── Default provider hosts ────────────────────────────────────────────────
    'providers' => [
        'zazy_host' => env('ZAZY_HOST', 'http://live.zazytv.com'),
        'ugeen_host' => env('UGEEN_HOST', 'http://ugeen.live'),
    ],

];
