<?php

use App\Models\Server;
use App\Services\Docker\DockerProvider;

beforeEach(function () {
   /** @var DockerProvider $dockerProvider */
    $dockerProvider =  $this->app->make(DockerProvider::class);
    $this->dockerProvider = $dockerProvider;
    $this->server = Server::factory()->create();
});

it('can create a docker network', function () {
    $uniqueName = 'test-network-' . uniqid();
    dd($uniqueName);
    $this->dockerProvider->forServer($this->server)->createNetwork('test-network');
});
