<?php

use App\Models\Server;

it('detects debian family by ID', function () {
    $server = new Server;

    $result = $server->resolveSupportedOsType(collect([
        'ID' => 'debian',
        'ID_LIKE' => '',
    ]));

    expect($result)->not->toBeFalse()
        ->and((string) $result)->toContain('debian');
});

it('detects alpine by ID', function () {
    $server = new Server;

    $result = $server->resolveSupportedOsType(collect([
        'ID' => 'alpine',
        'ID_LIKE' => '',
    ]));

    expect($result)->not->toBeFalse()
        ->and((string) $result)->toContain('alpine');
});

it('detects supported family using ID_LIKE fallback', function () {
    $server = new Server;

    $result = $server->resolveSupportedOsType(collect([
        'ID' => 'linuxmint',
        'ID_LIKE' => 'ubuntu debian',
    ]));

    expect($result)->not->toBeFalse()
        ->and((string) $result)->toContain('ubuntu')
        ->and((string) $result)->toContain('debian');
});

it('returns false for unsupported os identifiers', function () {
    $server = new Server;

    $result = $server->resolveSupportedOsType(collect([
        'ID' => 'freebsd',
        'ID_LIKE' => '',
    ]));

    expect($result)->toBeFalse();
});
