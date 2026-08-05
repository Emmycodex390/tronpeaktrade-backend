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
     
    'facepp' => [
    'key' => env('FACEPP_API_KEY'),
    'secret' => env('FACEPP_API_SECRET'),
    'base_url' => env('FACEPP_BASE_URL', 'https://api-us.faceplusplus.com/facepp/v3/compare'),
    'match_threshold' => intval(env('FACEPP_MATCH_THRESHOLD', 80)),
    ],
     
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:support@fexistrade.com'),
    ],

    // Free CoinGecko "Demo" plan API key. Not required for the keyless
    // public API to work at all, but the keyless tier shares its rate
    // limit across every app hitting it from the same IP range —
    // painful on shared hosts like Render's free tier. A Demo key gets
    // its own per-key limit instead. Sign up free at
    // https://www.coingecko.com/en/api/pricing (Demo plan).
    'coingecko' => [
        'key' => env('COINGECKO_API_KEY'),
    ],

];
