<?php

namespace Tests\Integration\App\Services\Docker;

use App\Models\Server;
use App\Services\Docker\DockerProvider;

beforeEach(function () {
    /** @var DockerProvider $dockerProvider */
    $dockerProvider = $this->app->make(DockerProvider::class);
    $this->dockerProvider = $dockerProvider;
    $this->server = Server::factory()->create(['ip' => '127.0.0.1']);
});

it('can create and clean up a docker network', function () {
    $uniqueName = 'coolify-test-network-'.uniqid();

    // create network
    $networkId = $this->dockerProvider->forServer($this->server)->createNetwork($uniqueName);

    expect($networkId)->not()->toBeNull()
        ->not()->toBeEmpty();

    // remove network
    $output = $this->dockerProvider->forServer($this->server)->destroyNetwork($uniqueName);
    expect($output->result)->toBe($uniqueName);
});
