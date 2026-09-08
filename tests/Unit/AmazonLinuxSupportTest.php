<?php

use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;

it('includes amzn in the supported OS list', function () {
    $rhel = collect(SUPPORTED_OS)->first(fn ($os) => str($os)->contains('amzn'));
    expect($rhel)->not->toBeNull();
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

it('InstallPrerequisites uses findutils for amazon linux', function () {
    $action = new InstallPrerequisites;
    $reflection = new ReflectionClass($action);

    $source = file_get_contents($reflection->getFileName());

    expect($source)
        ->toContain("contains('amzn')")
        ->toContain('findutils');
});

it('InstallDocker uses native amazon repos not centos docker repo', function () {
    $action = new InstallDocker;
    $reflection = new ReflectionClass($action);

    $source = file_get_contents($reflection->getFileName());

    expect($source)
        ->toContain("contains('amzn')")
        ->toContain('getAmazonLinuxDockerInstallCommand')
        ->toContain('dnf install -y docker')
        ->not->toContain('DOCKER_CONFIG=${DOCKER_CONFIG');
});
