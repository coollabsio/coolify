<?php
return [
    'ssh' =>[
        'connection_timeout' => 10,
        'server_interval' => 20,
        'command_timeout' => 7200,
    ],
    'waitlist' => [
        'expiration' => 10,
    ],
    'invitation' => [
        'link' => [
            'base_url' => '/invitations/',
            'expiration' => 10,
        ],
    ],
    'services' => [
        'official' => 'https://cdn.coollabs.io/coolify/service-templates.json',
    ],
    'limits' => [
        'trial_period'=> 7,
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
