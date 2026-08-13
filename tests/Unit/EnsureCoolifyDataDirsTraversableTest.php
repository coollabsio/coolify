<?php

use App\Actions\Migration\EnsureCoolifyDataDirsTraversable;
use App\Models\Server;

beforeEach(function () {
    $this->server = Mockery::mock(Server::class)->makePartial();
    $this->server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $this->server->shouldReceive('setAttribute')->andReturnSelf();
});

afterEach(function () {
    Mockery::close();
});

test('opens coolify data directories for non-root ssh traversal without world-readable files', function () {
    $joined = implode("\n", EnsureCoolifyDataDirsTraversable::commands());

    expect($joined)
        ->toContain('/data/coolify')
        ->toContain('-type d')
        ->toContain('chmod a+x')
        ->not->toContain('chmod -R 700 /data/coolify')
        ->not->toContain('chmod -R 755');
});

test('traversal commands survive non-root sudo rewriting', function () {
    $rewritten = parseCommandsByLineForSudo(collect(EnsureCoolifyDataDirsTraversable::commands()), $this->server);

    foreach ($rewritten as $command) {
        expect($command)
            ->not->toContain('sudo (')
            ->not->toMatch('/\|\|\s*sudo\s*\(/');
    }

    expect(implode("\n", $rewritten))
        ->toContain('chmod a+x')
        ->toContain('/data/coolify');
});
