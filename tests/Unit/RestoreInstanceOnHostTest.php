<?php

use App\Actions\Migration\ReassignDestinationsOnRemoteInstance;
use App\Actions\Migration\RestoreInstanceOnHost;
use App\Models\Server;

beforeEach(function () {
    $this->server = Mockery::mock(Server::class)->makePartial();
    $this->server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $this->server->shouldReceive('setAttribute')->andReturnSelf();
});

afterEach(function () {
    Mockery::close();
});

test('restore syncs the postgres role password to match target .env after pg_restore', function () {
    $commands = RestoreInstanceOnHost::syncDatabasePasswordCommands();
    $joined = implode("\n", $commands);

    expect($joined)
        ->toContain('/data/coolify/source/.env')
        ->toContain('DB_PASSWORD')
        ->toContain('ALTER ROLE')
        ->toContain('coolify-db')
        ->not->toContain('>> /data/coolify/source/.env');
});

test('restore commands copy the dump into coolify-db instead of piping stdin over ssh', function () {
    $commands = RestoreInstanceOnHost::restoreDatabaseCommands('/tmp/coolify-instance-migration-abc-coolify-db.dmp');

    $joined = implode("\n", $commands);

    expect($joined)
        ->toContain('docker cp')
        ->toContain('/tmp/coolify-instance-migration-abc-coolify-db.dmp')
        ->toContain('coolify-db:/tmp/coolify-instance.dmp')
        ->toContain('pg_restore')
        ->not->toContain('docker exec -i')
        ->not->toContain('< /tmp/');
});

test('restore staging uploads flat files under /tmp without a subdirectory', function () {
    $path = RestoreInstanceOnHost::stagingFilePath('abc', 'coolify-db.dmp');

    expect($path)->toBe('/tmp/coolify-instance-migration-abc-coolify-db.dmp')
        ->and($path)->not->toContain('/coolify-db.dmp/')
        ->and(substr_count($path, '/'))->toBe(2);
});

test('shouldChangeOwnership strips shell quotes from mkdir paths', function () {
    expect(shouldChangeOwnership("'/tmp/coolify-instance-migration-abc'"))->toBeTrue()
        ->and(shouldChangeOwnership('"/data/coolify/backups"'))->toBeTrue()
        ->and(shouldChangeOwnership("'/var/log'"))->toBeFalse();
});

test('env update does not use a non-root shell redirect into .env', function () {
    $commands = RestoreInstanceOnHost::updateEnvCommands('base64:testkey');
    $joined = implode("\n", $commands);

    expect($joined)
        ->toContain('/data/coolify/source/.env')
        ->toContain('APP_KEY')
        ->toContain('tee -a')
        ->not->toContain('>> /data/coolify/source/.env');
});

test('env update writes .env as root after sudo rewriting', function () {
    $commands = RestoreInstanceOnHost::updateEnvCommands('base64:testkey');
    $rewritten = parseCommandsByLineForSudo(collect($commands), $this->server);
    $joined = implode("\n", $rewritten);

    expect($joined)
        ->toContain('sudo sh -c')
        ->toContain('sudo sed -i')
        ->not->toContain('>> /data/coolify/source/.env')
        ->not->toMatch('/sudo printf.*>> \\/data\\/coolify/');

    foreach ($rewritten as $command) {
        expect($command)
            ->not->toContain('sudo (')
            ->not->toMatch('/\|\|\s*sudo\s*\(/');
    }
});

test('restart coolify does not cd into root-owned source directory', function () {
    $commands = RestoreInstanceOnHost::restartCoolifyCommands();
    $joined = implode("\n", $commands);

    expect($joined)
        ->toContain('--project-directory /data/coolify/source')
        ->toContain('/data/coolify/source/docker-compose.yml')
        ->not->toContain('cd /data/coolify');
});

test('all restore remote commands are safe for non-root sudo rewriting', function () {
    $commands = RestoreInstanceOnHost::allRemoteCommands();
    $joined = implode("\n", $commands);
    $rewritten = parseCommandsByLineForSudo(collect($commands), $this->server);
    $rewrittenJoined = implode("\n", $rewritten);

    expect($joined)
        ->toContain('ALTER ROLE')
        ->toContain('chmod a+x')
        ->not->toContain('cd /data/coolify')
        ->not->toContain('>> /data/coolify')
        ->not->toContain('docker exec -i');

    expect($rewrittenJoined)
        ->toContain('sudo docker compose --project-directory /data/coolify/source')
        ->toContain('sudo sh -c')
        ->not->toContain('cd /data/coolify');

    foreach ($rewritten as $command) {
        expect($command)
            ->not->toContain('sudo (')
            ->not->toMatch('/\|\|\s*sudo\s*\(/')
            ->not->toMatch('/^cd /');
    }
});

test('restore commands survive non-root sudo rewriting', function () {
    $commands = RestoreInstanceOnHost::restoreDatabaseCommands('/tmp/coolify-instance-migration-abc-coolify-db.dmp');
    $rewritten = parseCommandsByLineForSudo(collect($commands), $this->server);

    foreach ($rewritten as $command) {
        expect($command)
            ->not->toContain('sudo (')
            ->not->toMatch('/\|\|\s*sudo\s*\(/');
    }

    expect(implode("\n", $rewritten))
        ->toContain('sudo docker cp')
        ->toContain('sudo docker exec coolify-db sh -c');
});

test('database password sync survives non-root sudo rewriting', function () {
    $rewritten = parseCommandsByLineForSudo(collect(RestoreInstanceOnHost::syncDatabasePasswordCommands()), $this->server);
    $joined = implode("\n", $rewritten);

    expect($joined)
        ->toContain('sudo bash -c')
        ->toContain('ALTER ROLE')
        ->toContain('DB_PASSWORD');

    foreach ($rewritten as $command) {
        expect($command)
            ->not->toContain('sudo (')
            ->not->toMatch('/\|\|\s*sudo\s*\(/');
    }
});

test('destination reassignment commands survive non-root sudo rewriting', function () {
    $rewritten = parseCommandsByLineForSudo(collect(ReassignDestinationsOnRemoteInstance::reassignCommands()), $this->server);

    foreach ($rewritten as $command) {
        expect($command)
            ->not->toContain('sudo (')
            ->not->toMatch('/\|\|\s*sudo\s*\(/');
    }

    expect(implode("\n", $rewritten))
        ->toContain('sudo docker cp')
        ->toContain('sudo docker exec coolify-db')
        ->not->toContain('artisan tinker');
});
