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
     * Why the entry names no usable destination, or null when it does.
     */
    protected function destinationError(Server $server, ?string $network): ?string
    {
        if ($this->destination($server, $network) !== null) {
            return null;
        }
        $networks = $server->destinations()->pluck('network')->sort()->implode(', ');
        if ($networks === '') {
            return "Server \"{$server->name}\" has no Docker destination. Add one in Coolify first.";
        }

        return $network === null
            ? "Server \"{$server->name}\" has more than one destination. Add \"destination\" with one of: {$networks}."
            : "Server \"{$server->name}\" has no destination with network \"{$network}\". Use one of: {$networks}.";
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
