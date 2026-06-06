<?php

use App\Models\InstanceSettings;
use App\Services\Docker\DockerNetworkCatalogRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\InteractsWithDockerNetworks;

uses(RefreshDatabase::class, InteractsWithDockerNetworks::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $this->server = $this->createFunctionalServer();
});

it('avoids redundant scans within short cooldown', function () {
    $calls = 0;
    $refresher = $this->fakeCatalogRefresher($calls);

    $refresher->refresh($this->server);
    $refresher->refresh($this->server);

    expect($calls)->toBe(1);
});

it('forces refresh when requested even inside cooldown', function () {
    $calls = 0;
    $refresher = $this->fakeCatalogRefresher($calls);

    $refresher->refresh($this->server);
    $refresher->refresh($this->server, true);

    expect($calls)->toBe(2);
});
