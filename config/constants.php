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
            'basic' => 1,
            'pro' => 10,
            'ultimate' => 25,
        ],
        'smtp' => [
            'basic' => false,
            'pro' => true,
            'ultimate' => true,
        ],
    ],
];
