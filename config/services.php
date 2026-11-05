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

    'inter' => [
        'url'            => env('INTER_URL'),
        'client_id'      => env('INTER_CLIENT_ID'),
        'client_secret'  => env('INTER_CLIENT_SECRET'),
        'cert'           => env('INTER_CERT_PATH'),
        'key'            => env('INTER_KEY_PATH'),
        'key_senha'      => env('INTER_KEY_SENHA'),
        'chave_pix'      => env('INTER_CHAVE_PIX'),
        'conta_corrente' => env('INTER_CONTA_CORRENTE'),
        'webhook_token'  => env('INTER_WEBHOOK_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
