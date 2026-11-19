<?php

use App\Models\Server;

it('matches the supported OS family from os-release', function (string $osRelease, string|false $expected) {
    $result = Server::supportedOsFromOsRelease($osRelease);

    expect($result === false ? false : $result->value())->toBe($expected);
})->with([
    'ubuntu by ID' => ["ID=ubuntu\nID_LIKE=debian", 'ubuntu debian raspbian pop'],
    'arch by ID' => ['ID=arch', 'arch'],
    'rocky by ID' => ["ID=\"rocky\"\nID_LIKE=\"rhel centos fedora\"", 'centos fedora rhel ol rocky amzn almalinux'],
    'omarchy by ID_LIKE' => ["NAME=\"Omarchy\"\nID=omarchy\nID_LIKE=arch", 'arch'],
    'manjaro by ID_LIKE' => ["ID=manjaro\nID_LIKE=arch", 'arch'],
    'linux mint by ID_LIKE' => ["ID=linuxmint\nID_LIKE=\"ubuntu debian\"", 'ubuntu debian raspbian pop'],
    'single-quoted ID_LIKE' => ["ID=zorin\nID_LIKE='ubuntu debian'", 'ubuntu debian raspbian pop'],
    'unsupported without ID_LIKE' => ['ID=gentoo', false],
    'unsupported ID_LIKE' => ["ID=foo\nID_LIKE=bar", false],
    'empty output' => ['', false],
]);

it('returns false for null os-release output', function () {
    expect(Server::supportedOsFromOsRelease(null))->toBeFalse();
});
