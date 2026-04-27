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
    // The zazy-automation container exposes a Flask API on port 5000.
    // Both containers must be on dokploy-network.
    'automation_api' => [
        'url' => env('AUTOMATION_API_URL', 'http://zazy-automation:5000'),
        'key' => env('AUTOMATION_API_KEY', ''),
    ],

    // ── Zazy Automation Configuration ─────────────────────────────────────────
    'zazy_automation' => [
        'webhook_token' => env('ZAZY_WEBHOOK_TOKEN', ''),
    ],

    // ── Default provider hosts ────────────────────────────────────────────────
    'providers' => [
        'zazy_host' => env('ZAZY_HOST', 'http://live.zazytv.com'),
    ],

];
