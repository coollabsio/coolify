<?php

use App\Models\Server;

it('resolves Debian family when ID is debian', function () {
    $releaseData = Server::parseOsReleaseContent(<<<'EOT'
        PRETTY_NAME="Debian GNU/Linux 13 (trixie)"
        ID=debian
        VERSION_ID="13"
        VERSION_CODENAME=trixie
        EOT
    );

    $supportedOs = Server::resolveSupportedOsTypeFromReleaseData($releaseData);

    expect($supportedOs)->not->toBeFalse()
        ->and($supportedOs->value())->toBe('ubuntu debian raspbian pop');
});

it('resolves Debian family when ID_LIKE includes debian', function () {
    $releaseData = Server::parseOsReleaseContent(<<<'EOT'
        NAME="Kali GNU/Linux"
        ID=kali
        ID_LIKE=debian
        VERSION_ID="2026.1"
        EOT
    );

    $supportedOs = Server::resolveSupportedOsTypeFromReleaseData($releaseData);

    expect($supportedOs)->not->toBeFalse()
        ->and($supportedOs->value())->toBe('ubuntu debian raspbian pop');
});

it('supports numeric VERSION_CODENAME value for Debian 13 releases', function () {
    $releaseData = Server::parseOsReleaseContent(<<<'EOT'
        PRETTY_NAME="Debian GNU/Linux 13"
        ID=debian
        VERSION_ID="13"
        VERSION_CODENAME="13"
        EOT
    );

    $supportedOs = Server::resolveSupportedOsTypeFromReleaseData($releaseData);

    expect($supportedOs)->not->toBeFalse()
        ->and($supportedOs->value())->toBe('ubuntu debian raspbian pop')
        ->and(data_get($releaseData, 'VERSION_CODENAME'))->toBe('13');
});

it('returns false for unsupported distributions', function () {
    $releaseData = Server::parseOsReleaseContent(<<<'EOT'
        NAME="NixOS"
        ID=nixos
        ID_LIKE=linux
        VERSION_ID="25.05"
        EOT
    );

    $supportedOs = Server::resolveSupportedOsTypeFromReleaseData($releaseData);

    expect($supportedOs)->toBeFalse();
});
