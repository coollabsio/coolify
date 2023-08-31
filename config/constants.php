<?php
return [
    'waitlist' => [
        'expiration' => 10,
    ],
    'invitation' => [
        'link' => [
            'base_url' => '/invitations/',
            'expiration' => 10,
        ],
    ],
    'limits' => [
        'server' => [
            'zero' => 0,
            'self-hosted' => 999999999999,
            'basic' => 1,
            'pro' => 10,
            'ultimate' => 25,
        ],
        'email' => [
            'zero' => false,
            'self-hosted' => true,
            'basic' => false,
            'pro' => true,
            'ultimate' => true,
        ],
    ],
];
