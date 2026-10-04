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

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        // Cron expression for the scheduled resync of all targets (default: hourly).
        'sync_schedule' => env('GITHUB_SYNC_SCHEDULE', '0 * * * *'),
        // Where repository clones are stored, and the largest repository (per GitHub's
        // reported size) that gets cloned.
        'clone_path' => str_starts_with($clonePath = env('GITHUB_CLONE_PATH', 'storage/app/clones'), '/') ? $clonePath : base_path($clonePath),
        'clone_max_size_mb' => (int) env('GITHUB_CLONE_MAX_SIZE_MB', 512),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
