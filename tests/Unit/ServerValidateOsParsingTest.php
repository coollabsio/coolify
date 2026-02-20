<?php

use App\Models\Server;

it('detects debian via id token', function () {
    $osRelease = <<<'TXT'
NAME="Debian GNU/Linux"
VERSION_ID="13"
ID=debian
TXT;

    $detected = Server::detectSupportedOsFromRelease($osRelease);

    expect($detected)->toBe('ubuntu debian raspbian pop');
});

it('detects debian family via id_like token when id is custom', function () {
    $osRelease = <<<'TXT'
NAME="Custom Debian Derivative"
ID=mycustomos
ID_LIKE="debian ubuntu"
VERSION_ID="13"
TXT;

    $detected = Server::detectSupportedOsFromRelease($osRelease);

    expect($detected)->toBe('ubuntu debian raspbian pop');
});

it('detects rhel family via id_like token with multiple markers', function () {
    $osRelease = <<<'TXT'
NAME="Rocky Linux"
ID=rocky-clone
ID_LIKE="rhel centos fedora"
VERSION_ID="9"
TXT;

    $detected = Server::detectSupportedOsFromRelease($osRelease);

    expect($detected)->toBe('centos fedora rhel ol rocky amzn almalinux');
});

it('returns false when id and id_like are both empty or unsupported', function () {
    $emptyTokens = <<<'TXT'
NAME="Unknown Linux"
ID=""
ID_LIKE=""
TXT;

    $unsupported = <<<'TXT'
NAME="Unknown Linux"
ID=unknownos
ID_LIKE="strangeos"
TXT;

    expect(Server::detectSupportedOsFromRelease($emptyTokens))->toBeFalse();
    expect(Server::detectSupportedOsFromRelease($unsupported))->toBeFalse();
});

it('ignores malformed or non key-value lines in os-release payload', function () {
    $osRelease = <<<'TXT'
this is not key value

ID=debian
MALFORMED
ID_LIKE="debian"
TXT;

    $detected = Server::detectSupportedOsFromRelease($osRelease);

    expect($detected)->toBe('ubuntu debian raspbian pop');
});
