<?php

namespace App\Services\ProjectConfig\Adapters\Concerns;

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\ProjectConfig\ApplyContext;

/**
 * Finds the server and destination that a resource entry names, in the team of the link.
 */
trait ResolvesDestination
{
    protected function server(array $desired, ApplyContext $ctx): ?Server
    {
        $name = $desired['server'] ?? null;
        if (! is_string($name)) {
            return null;
        }
        $servers = Server::query()->where('team_id', $ctx->teamId)->get()->filter(fn (Server $server) => $server->name === $name);

        return $servers->count() === 1 ? $servers->first() : null;
    }

    protected function destination(Server $server, ?string $network): StandaloneDocker|SwarmDocker|null
    {
        $destinations = $server->destinations();
        if ($network === null) {
            return $destinations->count() === 1 ? $destinations->first() : null;
        }

        return $destinations->first(fn ($destination) => $destination->network === $network);
    }

    /**
     * @return array{server?: string, destination?: string}
     */
    protected function readDestination(?object $destination): array
    {
        $server = $destination?->server;
        if ($server === null) {
            return [];
        }
        $result = ['server' => $server->name];
        if ($server->destinations()->count() > 1) {
            $result['destination'] = $destination->network;
        }

        return $result;
    }
}
