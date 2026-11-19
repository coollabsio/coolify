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

test('ConvertDns', function () {
    $input = '--dns 10.0.0.10 --dns=1.1.1.1';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'dns' => ['10.0.0.10', '1.1.1.1'],
    ]);
});

test('ConvertDnsWithOtherOptions', function () {
    $input = '--cap-add=NET_ADMIN --dns 10.0.0.10 --init';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'cap_add' => ['NET_ADMIN'],
        'dns' => ['10.0.0.10'],
        'init' => true,
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

test('ConvertIp6', function () {
    $input = '--ip6 2001:db8::1';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'ip6' => ['2001:db8::1'],
    ]);
});

test('ConvertIpAndIp6Together', function () {
    $input = '--ip 172.20.0.5 --ip6 2001:db8::1';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'ip' => ['172.20.0.5'],
        'ip6' => ['2001:db8::1'],
    ]);
});

test('ConvertSecurityOptWithHyphenatedValue', function () {
    $input = '--cap-drop ALL --security-opt no-new-privileges:true --security-opt=apparmor:unconfined';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'cap_drop' => ['ALL'],
        'security_opt' => ['no-new-privileges:true', 'apparmor:unconfined'],
    ]);
});

test('ConvertQuotedSecurityOpt', function (string $input) {
    expect(convertDockerRunToCompose($input))->toBe([
        'security_opt' => ['no-new-privileges:true'],
    ]);
})->with([
    '--security-opt="no-new-privileges:true"',
    "--security-opt='no-new-privileges:true'",
    '--security-opt "no-new-privileges:true"',
    "--security-opt 'no-new-privileges:true'",
]);

test('ConvertQuotedValuesForListAndScalarOptions', function () {
    $input = '--cap-add "NET_ADMIN" --cap-drop \'ALL\' --sysctl "net.core.somaxconn=1024" --device "/dev/snd" --dns "8.8.8.8" --ip="10.0.0.5" --shm-size "2g" --hostname \'my-host\' --security-opt "label=type:my type"';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'cap_add' => ['NET_ADMIN'],
        'cap_drop' => ['ALL'],
        'sysctls' => ['net.core.somaxconn=1024'],
        'devices' => ['/dev/snd'],
        'dns' => ['8.8.8.8'],
        'ip' => ['10.0.0.5'],
        'shm_size' => '2g',
        'hostname' => 'my-host',
        'security_opt' => ['label=type:my type'],
    ]);
});

test('ConvertUnescapesDoubleQuotedValue', function () {
    $input = '--security-opt "label=type:\"my\" type"';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'security_opt' => ['label=type:"my" type'],
    ]);
});

test('ConvertBooleanFlagFollowedByShortFlag', function () {
    $input = '--init -e FOO=bar --privileged -v /a:/b';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'init' => true,
        'privileged' => true,
    ]);
});

test('ConvertBooleanFlagWithInlineValue', function () {
    $input = '--privileged=false --init=true';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'privileged' => false,
        'init' => true,
    ]);
});

test('ConvertBooleanFlagIgnoresSpaceSeparatedWord', function () {
    $input = '--privileged false --init "x"';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'privileged' => true,
        'init' => true,
    ]);
});

test('ConvertUlimitWithSingleValue', function () {
    $input = '--ulimit nofile=1024 --ulimit nproc=512:1024';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'ulimits' => [
            'nofile' => '1024',
            'nproc' => [
                'soft' => '512',
                'hard' => '1024',
            ],
        ],
    ]);
});

test('ConvertIgnoresUlimitWithoutType', function () {
    $input = '--ulimit 1024 --init';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'init' => true,
    ]);
});

test('ConvertIgnoresOptionWithoutValue', function () {
    $input = '--cap-add -e FOO=bar --cap-add NET_ADMIN --ulimit --dns';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'cap_add' => ['NET_ADMIN'],
    ]);
});

test('ConvertIgnoresEmptyQuotedValue', function () {
    $input = '--security-opt="" --init';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'init' => true,
    ]);
});

test('ConvertDoesNotParseOptionsInsideQuotedEntrypoint', function () {
    $input = '--entrypoint "sh -c \'exec app --privileged --cap-add=SYS_ADMIN\'" --init';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'entrypoint' => "sh -c 'exec app --privileged --cap-add=SYS_ADMIN'",
        'init' => true,
    ]);
});

test('ConvertValueAfterMultipleWhitespace', function () {
    $input = "--cap-add  NET_ADMIN --security-opt \t no-new-privileges:true --dns\t\t1.1.1.1";
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'cap_add' => ['NET_ADMIN'],
        'security_opt' => ['no-new-privileges:true'],
        'dns' => ['1.1.1.1'],
    ]);
});

test('DatabaseCustomUlimitReplacesDefaultOfSameType', function () {
    $dockerCompose = [
        'services' => [
            'db' => [
                'image' => 'clickhouse/clickhouse-server',
                'ulimits' => ['nofile' => ['soft' => 262144, 'hard' => 262144]],
            ],
        ],
    ];
    $options = convertDockerRunToCompose('--ulimit nofile=1024:2048 --ulimit nproc=512');
    $output = generateCustomDockerRunOptionsForDatabases($options, $dockerCompose, 'db', 'coolify');
    expect($output['services']['db']['ulimits'])->toBe([
        'nofile' => ['soft' => '1024', 'hard' => '2048'],
        'nproc' => '512',
    ]);
});

test('DatabaseDefaultUlimitIsKeptWhenNotOverridden', function () {
    $dockerCompose = [
        'services' => [
            'db' => [
                'image' => 'clickhouse/clickhouse-server',
                'ulimits' => ['nofile' => ['soft' => 262144, 'hard' => 262144]],
            ],
        ],
    ];
    $options = convertDockerRunToCompose('--ulimit nproc=512');
    $output = generateCustomDockerRunOptionsForDatabases($options, $dockerCompose, 'db', 'coolify');
    expect($output['services']['db']['ulimits'])->toBe([
        'nofile' => ['soft' => 262144, 'hard' => 262144],
        'nproc' => '512',
    ]);
});

test('ConvertValueWithQuotedPart', function () {
    $input = '--sysctl net.ipv4.ping_group_range="0 2147483647" --ulimit nofile="1024:2048" --security-opt label="type:svirt_apache_t"';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'sysctls' => ['net.ipv4.ping_group_range=0 2147483647'],
        'ulimits' => [
            'nofile' => [
                'soft' => '1024',
                'hard' => '2048',
            ],
        ],
        'security_opt' => ['label=type:svirt_apache_t'],
    ]);
});

test('ConvertGpusCount', function () {
    $input = '--gpus 2';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'deploy' => [
            'resources' => [
                'reservations' => [
                    'devices' => [
                        [
                            'driver' => 'nvidia',
                            'capabilities' => ['gpu'],
                            'count' => 2,
                        ],
                    ],
                ],
            ],
        ],
    ]);
});

test('ConvertInteractiveShortFlag', function () {
    // -i keeps STDIN open so `docker attach` can send input (e.g. game server consoles).
    expect(convertDockerRunToCompose('-i'))->toBe([
        'stdin_open' => true,
    ]);
});

test('ConvertTtyShortFlag', function () {
    expect(convertDockerRunToCompose('-t'))->toBe([
        'tty' => true,
    ]);
});

test('ConvertInteractiveTtyCombinedShortFlag', function () {
    expect(convertDockerRunToCompose('-it'))->toBe([
        'stdin_open' => true,
        'tty' => true,
    ]);
});

test('ConvertTtyInteractiveCombinedShortFlag', function () {
    expect(convertDockerRunToCompose('-ti'))->toBe([
        'stdin_open' => true,
        'tty' => true,
    ]);
});

test('ConvertInteractiveTtyLongFlags', function () {
    expect(convertDockerRunToCompose('--interactive --tty'))->toBe([
        'stdin_open' => true,
        'tty' => true,
    ]);
});

test('ConvertInteractiveTtyWithOtherOptions', function () {
    expect(convertDockerRunToCompose('-it --cap-add SYS_ADMIN'))->toBe([
        'cap_add' => ['SYS_ADMIN'],
        'stdin_open' => true,
        'tty' => true,
    ]);
});

test('DoesNotSetInteractiveTtyForUnrelatedShortFlags', function () {
    // -d (detach) and other short flags without i/t must not enable stdin/tty.
    expect(convertDockerRunToCompose('-d'))->toBe([]);
});

it('does not read interactive flags inside entrypoint arguments', function () {
    expect(convertDockerRunToCompose('--entrypoint "python -i -t script.py"'))
        ->toBe(['entrypoint' => 'python -i -t script.py']);
});

it('does not treat arbitrary short words as interactive flags', function () {
    expect(convertDockerRunToCompose('-invalid'))->toBe([]);
});

test('ConvertRuntimeHardeningOptions', function () {
    $input = '--add-host host.docker.internal:host-gateway --add-host=db:10.0.0.5 --pids-limit 100 --runtime runc --read-only --tmpfs /run:rw,size=64m --tmpfs /tmp';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'extra_hosts' => ['host.docker.internal:host-gateway', 'db:10.0.0.5'],
        'pids_limit' => 100,
        'runtime' => 'runc',
        'read_only' => true,
        'tmpfs' => ['/run:rw,size=64m', '/tmp'],
    ]);
});

test('ConvertNegativePidsLimitAndDisabledReadOnly', function () {
    $input = '--pids-limit -1 --read-only=false';
    $output = convertDockerRunToCompose($input);
    expect($output)->toBe([
        'pids_limit' => -1,
        'read_only' => false,
    ]);
});

test('UnsupportedDockerRunOptions', function () {
    $input = '--network=host --name app -e FOO=bar -v /a:/b --entrypoint "sh -c \'run --verbose\'" --cap-add NET_ADMIN --pids-limit -1 ---privileged --network bridge';
    expect(unsupportedDockerRunOptions($input))->toBe(['--network', '--name', '-e', '-v']);
});

test('UnsupportedDockerRunOptionsIsEmptyForSupportedOptions', function () {
    expect(unsupportedDockerRunOptions('--cap-drop ALL --security-opt no-new-privileges:true --tmpfs /tmp'))->toBe([])
        ->and(unsupportedDockerRunOptions(null))->toBe([]);
});

test('UnsupportedDockerRunOptionsSkipsInteractiveAndTtyFlags', function () {
    expect(unsupportedDockerRunOptions('-it -t --interactive --tty --cap-add NET_ADMIN'))->toBe([]);
});

test('SeccompProfileMountFlags', function () {
    $input = '--security-opt seccomp=/etc/docker/seccomp.json --security-opt "seccomp:/srv/app profile.json" --security-opt seccomp=/etc/../root/x.json --security-opt seccomp=unconfined --security-opt apparmor=unconfined';
    expect(seccompProfileMountFlags($input))->toBe("--mount 'type=bind,source=/etc/docker/seccomp.json,target=/etc/docker/seccomp.json,readonly'")
        ->and(seccompProfileMountFlags('--cap-drop ALL'))->toBe('')
        ->and(seccompProfileMountFlags(null))->toBe('');
});
