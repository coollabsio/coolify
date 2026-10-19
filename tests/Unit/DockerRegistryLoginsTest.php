<?php

use App\Services\DockerRegistryLogins;

it('reads registry names from auths and credHelpers without credentials', function () {
    $config = json_encode([
        'auths' => [
            'https://index.docker.io/v1/' => ['auth' => base64_encode('user:SECRET_TOKEN')],
            'ghcr.io' => ['auth' => base64_encode('user:SECRET_TOKEN')],
            'registry.example.com:5000' => [],
        ],
        'credHelpers' => [
            '123456789.dkr.ecr.eu-west-1.amazonaws.com' => 'ecr-login',
            'ghcr.io' => 'gh',
        ],
    ]);

    $registries = DockerRegistryLogins::parseConfig($config);

    expect($registries)->toBe([
        '123456789.dkr.ecr.eu-west-1.amazonaws.com' => ['source' => 'credHelpers', 'username' => null],
        'docker.io' => ['source' => 'auths', 'username' => 'user'],
        'ghcr.io' => ['source' => 'auths', 'username' => 'user'],
        'registry.example.com:5000' => ['source' => 'auths', 'username' => null],
    ])->and(json_encode($registries))->not->toContain('SECRET_TOKEN');
});

it('returns no registries for a missing or invalid config', function (?string $config) {
    expect(DockerRegistryLogins::parseConfig($config))->toBe([]);
})->with([null, '', 'not json', '{"auths": "broken"}', '{}']);

it('finds the registry of an image reference', function (string $image, string $registry) {
    expect(DockerRegistryLogins::registryFromImage($image))->toBe($registry);
})->with([
    ['nginx', 'docker.io'],
    ['library/nginx:latest', 'docker.io'],
    ['coollabsio/coolify', 'docker.io'],
    ['docker.io/coollabsio/coolify', 'docker.io'],
    ['ghcr.io/coollabsio/coolify:4', 'ghcr.io'],
    ['GHCR.IO/Owner/App', 'ghcr.io'],
    ['registry.example.com:5000/team/app', 'registry.example.com:5000'],
    ['localhost/app', 'localhost'],
    ['localhost:5000/app', 'localhost:5000'],
]);

it('reads the username from the auth value without keeping the password', function (array $entry, ?string $username) {
    $registries = DockerRegistryLogins::parseConfig(json_encode(['auths' => ['ghcr.io' => $entry]]));

    expect($registries['ghcr.io']['username'])->toBe($username)
        ->and(json_encode($registries))->not->toContain('pa:ss');
})->with([
    'password with colon' => [['auth' => base64_encode('octocat:pa:ss')], 'octocat'],
    'explicit username' => [['username' => 'robot$ci', 'auth' => base64_encode('ignored:pa:ss')], 'robot$ci'],
    'invalid base64' => [['auth' => '%%%'], null],
    'no colon' => [['auth' => base64_encode('pa:ss')], 'pa'],
    'empty entry' => [[], null],
]);

it('accepts registry hosts with the registry pattern', function (string $registry) {
    expect(preg_match(DockerRegistryLogins::REGISTRY_PATTERN, $registry))->toBe(1);
})->with([
    'ghcr.io',
    'registry.example.com:5000',
    'localhost',
    '192.0.2.10:5000',
    '[2a01:4f8::1]',
    '[2a01:4f8::1]:5000',
    '[::ffff:192.0.2.1]:5000',
]);

it('rejects unsafe registry hosts with the registry pattern', function (string $registry) {
    expect(preg_match(DockerRegistryLogins::REGISTRY_PATTERN, $registry))->toBe(0);
})->with([
    'ghcr.io;id',
    '[2a01:4f8::1$(id)]:5000',
    '[2a01:4f8::1 ]',
    '[2a01:4f8::zz]',
    '[2a01:4f8::1',
    '2a01:4f8::1]',
    '[]',
]);

it('reads IPv6 registries from the docker config and image references', function () {
    expect(DockerRegistryLogins::parseConfig(json_encode(['auths' => ['[2a01:4f8::1]:5000' => []]])))
        ->toBe(['[2a01:4f8::1]:5000' => ['source' => 'auths', 'username' => null]])
        ->and(DockerRegistryLogins::registryFromImage('[2a01:4f8::1]:5000/team/app:1'))->toBe('[2a01:4f8::1]:5000');
});
