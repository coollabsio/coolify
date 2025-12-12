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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'bitbucket' => [
        'custom_label' => env('BITBUCKET_LOGIN_LABEL'),
    ],

    'discord' => [
        'custom_label' => env('DISCORD_LOGIN_LABEL'),
    ],

    'github' => [
        'custom_label' => env('GITHUB_LOGIN_LABEL'),
    ],

    'gitlab' => [
        'custom_label' => env('GITLAB_LOGIN_LABEL'),
    ],

    'infomaniak' => [
        'custom_label' => env('INFOMANIAK_LOGIN_LABEL'),
    ],

    'azure' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect' => env('AZURE_REDIRECT_URI'),
        'tenant' => env('AZURE_TENANT_ID'),
        'proxy' => env('AZURE_PROXY'),
        'custom_label' => env('AZURE_LOGIN_LABEL'),
    ],

    'authentik' => [
        'base_url' => env('AUTHENTIK_BASE_URL'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect' => env('AUTHENTIK_REDIRECT_URI'),
        'custom_label' => env('AUTHENTIK_LOGIN_LABEL'),
    ],

    'clerk' => [
        'client_id' => env('CLERK_CLIENT_ID'),
        'client_secret' => env('CLERK_CLIENT_SECRET'),
        'redirect' => env('CLERK_REDIRECT_URI'),
        'base_url' => env('CLERK_BASE_URL'),
        'custom_label' => env('CLERK_LOGIN_LABEL'),
    ],

    'openid' => [
        'client_id' => env('OPENID_CLIENT_ID'),
        'client_secret' => env('OPENID_CLIENT_SECRET'),
        'redirect' => env('OPENID_REDIRECT_URI'),
        'base_url' => env('OPENID_BASE_URL'),
        'custom_label' => env('OPENID_LOGIN_LABEL'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'tenant' => env('GOOGLE_TENANT'),
        'custom_label' => env('GOOGLE_LOGIN_LABEL'),
    ],

    'zitadel' => [
        'client_id' => env('ZITADEL_CLIENT_ID'),
        'client_secret' => env('ZITADEL_CLIENT_SECRET'),
        'redirect' => env('ZITADEL_REDIRECT_URI'),
        'base_url' => env('ZITADEL_BASE_URL'),
        'custom_label' => env('ZITADEL_LOGIN_LABEL'),
    ],

];
