<?php

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
