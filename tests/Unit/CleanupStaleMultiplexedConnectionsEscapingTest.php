<?php

use App\Jobs\CleanupStaleMultiplexedConnections;

it('has no raw user@ip string interpolation in CleanupStaleMultiplexedConnections', function () {
    $reflection = new ReflectionClass(CleanupStaleMultiplexedConnections::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->not->toContain('{$server->user}@{$server->ip}');
});
