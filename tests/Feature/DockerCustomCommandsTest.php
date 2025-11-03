<?php

test('Hostname', function () {
    $input = '--hostname=test';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'hostname' => 'test',
    ]);
});
test('HostnameWithoutEqualSign', function () {
    $input = '--hostname test';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'hostname' => 'test',
    ]);
});
test('HostnameWithoutEqualSignAndHyphens', function () {
    $input = '--hostname my-super-host';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'hostname' => 'my-super-host',
    ]);
});

test('HostnameWithHyphens', function () {
    $input = '--hostname=my-super-host';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'hostname' => 'my-super-host',
    ]);
});
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

test('ConvertEntrypointSimple', function () {
    $input = '--entrypoint /bin/sh';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => '/bin/sh',
    ]);
});

test('ConvertEntrypointWithEquals', function () {
    $input = '--entrypoint=/bin/bash';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => '/bin/bash',
    ]);
});

test('ConvertEntrypointWithArguments', function () {
    $input = '--entrypoint "sh -c npm install"';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => 'sh -c npm install',
    ]);
});

test('ConvertEntrypointWithSingleQuotes', function () {
    $input = "--entrypoint 'memcached -m 256'";
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => 'memcached -m 256',
    ]);
});

test('ConvertEntrypointWithOtherOptions', function () {
    $input = '--entrypoint /bin/bash --cap-add SYS_ADMIN --privileged';
    $output = convertDockerRunToCompose($input);
    expect($output)->toHaveKeys(['entrypoint', 'cap_add', 'privileged'])
        ->and($output['entrypoint'])->toBe('/bin/bash')
        ->and($output['cap_add'])->toBe(['SYS_ADMIN'])
        ->and($output['privileged'])->toBe(true);
});

test('ConvertEntrypointComplex', function () {
    $input = '--entrypoint "sh -c \'npm install && npm start\'"';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => "sh -c 'npm install && npm start'",
    ]);
});

test('ConvertEntrypointWithEscapedDoubleQuotes', function () {
    $input = '--entrypoint "python -c \"print(\'hi\')\""';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => "python -c \"print('hi')\"",
    ]);
});

test('ConvertEntrypointWithEscapedSingleQuotesInDoubleQuotes', function () {
    $input = '--entrypoint "sh -c \"echo \'hello\'\""';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => "sh -c \"echo 'hello'\"",
    ]);
});

test('ConvertEntrypointSingleQuotedWithDoubleQuotesInside', function () {
    $input = '--entrypoint \'python -c "print(\"hi\")"\'';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => 'python -c "print(\"hi\")"',
    ]);
});
