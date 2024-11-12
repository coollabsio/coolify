<?php

test('ConvertCapAdd', function () {
    $input = '--cap-add=NET_ADMIN --cap-add=NET_RAW --cap-add SYS_ADMIN';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'cap_add' => ['NET_ADMIN', 'NET_RAW', 'SYS_ADMIN'],
    ]);
});

test('ConvertIp', function () {
    $input = '--cap-add=NET_ADMIN --cap-add=NET_RAW --cap-add SYS_ADMIN --ip 127.0.0.1 --ip 127.0.0.2';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'cap_add' => ['NET_ADMIN', 'NET_RAW', 'SYS_ADMIN'],
        'ip' => ['127.0.0.1', '127.0.0.2'],
    ]);
});

test('ConvertPrivilegedAndInit', function () {
    $input = '---privileged --init';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'privileged' => true,
        'init' => true,
    ]);
});

test('ConvertUlimit', function () {
    $input = '--ulimit nofile=262144:262144';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'ulimits' => [
            'nofile' => [
                'soft' => '262144',
                'hard' => '262144',
            ],
        ],
    ]);
});
test('ConvertGpusWithGpuId', function () {
    $input = '--gpus "device=GPU-0000000000000000"';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'deploy' => [
            'resources' => [
                'reservations' => [
                    'devices' => [
                        [
                            'driver' => 'nvidia',
                            'capabilities' => ['gpu'],
                            'device_ids' => ['GPU-0000000000000000'],
                        ],
                    ],
                ],
            ],
        ],
    ]);
});

test('ConvertGpus', function () {
    $input = '--gpus all';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'deploy' => [
            'resources' => [
                'reservations' => [
                    'devices' => [
                        [
                            'driver' => 'nvidia',
                            'capabilities' => ['gpu'],
                        ],
                    ],
                ],
            ],
        ],
    ]);
});

test('ConvertGpusWithQuotes', function () {
    $input = '--gpus "device=0,1"';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'deploy' => [
            'resources' => [
                'reservations' => [
                    'devices' => [
                        [
                            'driver' => 'nvidia',
                            'capabilities' => ['gpu'],
                            'device_ids' => ['0', '1'],
                        ],
                    ],
                ],
            ],
        ],
    ]);
});
