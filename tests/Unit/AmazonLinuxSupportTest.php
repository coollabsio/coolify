<?php

use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;

it('includes amzn in the supported OS list', function () {
    $amzn = collect(SUPPORTED_OS)->first(fn ($os) => str($os)->contains('amzn'));
    expect($amzn)->not->toBeNull();
});

it('install script handles amazon linux prerequisites', function () {
    $installScript = file_get_contents(base_path('scripts/install.sh'));

    expect($installScript)
        ->toContain('"amzn"')
        ->toContain('dnf install -y findutils')
        ->toContain('dnf install -y wget git jq openssl');
});

it('nightly install script handles amazon linux prerequisites', function () {
    $installScript = file_get_contents(base_path('other/nightly/install.sh'));

    expect($installScript)
        ->toContain('"amzn"')
        ->toContain('dnf install -y findutils')
        ->toContain('dnf install -y wget git jq openssl');
});

it('install script handles amazon linux docker installation separately from rhel', function () {
    $installScript = file_get_contents(base_path('scripts/install.sh'));

    expect($installScript)
        ->toContain('"amzn")')
        ->toContain('dnf install docker -y');
});

it('nightly install script handles amazon linux docker installation separately from rhel', function () {
    $installScript = file_get_contents(base_path('other/nightly/install.sh'));

    expect($installScript)
        ->toContain('"amzn")')
        ->toContain('dnf install docker -y');
});

it('InstallPrerequisites installs findutils (xargs) for the rhel family', function () {
    $source = file_get_contents((new ReflectionClass(InstallPrerequisites::class))->getFileName());

    expect($source)->toContain('command -v xargs >/dev/null || dnf install -y findutils');
});

it('InstallDocker picks amazon linux by distro ID, not by the rhel family group', function () {
    $source = file_get_contents((new ReflectionClass(InstallDocker::class))->getFileName());

    expect($source)
        ->not->toContain("contains('amzn')")
        ->toContain('[ "$ID" = "amzn" ]')
        ->toContain('dnf install -y docker &&');
});
