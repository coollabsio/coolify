<?php
return [
    'waitlist' => [
        'confirmation_valid_for_minutes' => 10,
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
            'pro' => 3,
            'ultimate' => 9999999999999999999,
        ],
    ],
];
