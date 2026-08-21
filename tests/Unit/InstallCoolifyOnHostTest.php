<?php

use App\Actions\Migration\EnsureCoolifyDataDirsTraversable;
use App\Actions\Migration\InstallCoolifyOnHost;
use App\Models\Server;

beforeEach(function () {
    $this->server = Mockery::mock(Server::class)->makePartial();
    $this->server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $this->server->shouldReceive('setAttribute')->andReturnSelf();
});

afterEach(function () {
    Mockery::close();
});

test('install commands do not use parenthetical fallbacks that break sudo rewriting', function () {
    $commands = InstallCoolifyOnHost::installCommands('https://cdn.coollabs.io/coolify/install.sh');

    foreach ($commands as $command) {
        expect($command)
            ->not->toContain('|| (')
            ->not->toContain('&& (');
    }
});

test('install commands survive non-root sudo rewriting without sudo before parentheses', function () {
    $commands = InstallCoolifyOnHost::installCommands('https://cdn.coollabs.io/coolify/install.sh');
    $rewritten = parseCommandsByLineForSudo(collect($commands), $this->server);

    foreach ($rewritten as $command) {
        expect($command)
            ->not->toContain('sudo (')
            ->not->toMatch('/\|\|\s*sudo\s*\(/');
    }

    expect($rewritten[0])->toBe('command -v curl >/dev/null 2>&1 || sudo apt-get update -y || sudo true');
    expect($rewritten[1])->toBe('command -v curl >/dev/null 2>&1 || sudo apt-get install -y curl || sudo true');
    expect($rewritten[2])->toBe('command -v curl >/dev/null 2>&1 || sudo dnf install -y curl || sudo true');
    expect($rewritten[3])->toBe('command -v curl');
    expect($rewritten[4])->toStartWith('sudo curl -fsSL');
    expect($rewritten[5])->toBe('sudo bash /tmp/coolify-install.sh');
});

test('install is followed by opening coolify data dirs for non-root ssh', function () {
    $joined = implode("\n", EnsureCoolifyDataDirsTraversable::commands());

    expect($joined)
        ->toContain('chmod a+x')
        ->toContain('/data/coolify');
});

test('legacy parenthetical curl ensure command becomes invalid after sudo rewriting', function () {
    $legacy = [
        'command -v curl >/dev/null 2>&1 || (apt-get update -y && apt-get install -y curl) || (dnf install -y curl) || true',
    ];

    $rewritten = parseCommandsByLineForSudo(collect($legacy), $this->server);

    expect($rewritten[0])->toContain('sudo (');
});
