<?php

use App\Actions\Development\SeedDevelopmentQemuServer;
use App\Models\Server;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ServerSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds only the development testing host', function () {
    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ServerSeeder::class,
    ]);

    $testingHost = Server::query()->where('uuid', 'localhost')->first();

    expect($testingHost)
        ->not->toBeNull()
        ->and($testingHost->ip)->toBe('coolify-testing-host')
        ->and(Server::query()->count())->toBe(1);
});

it('restores localhost to the testing host after a qemu run', function () {
    config(['app.env' => 'local']);

    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ServerSeeder::class,
    ]);

    $localhost = Server::query()->findOrFail(0);
    $localhost->proxy->set('last_saved_proxy_configuration', 'saved proxy config');
    $localhost->save();
    $localhost->update([
        'ip' => '192.168.122.10',
        'user' => 'coolify',
        'description' => 'Development QEMU virtual machine',
    ]);
    SeedDevelopmentQemuServer::run('debian-root', false);

    $this->seed(ServerSeeder::class);

    $server = Server::query()->findOrFail(0);

    expect($server->uuid)->toBe('localhost')
        ->and($server->ip)->toBe('coolify-testing-host')
        ->and($server->user)->toBe('root')
        ->and($server->description)->toBe('This is a test docker container in development mode')
        ->and($server->proxy->last_saved_proxy_configuration)->toBe('saved proxy config')
        ->and(Server::query()->where('uuid', 'development-qemu-debian-root')->count())->toBe(1)
        ->and(Server::query()->count())->toBe(2);
});
