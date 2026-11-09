<?php

namespace App\Services\ProjectConfig\Children;

use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Extra servers that run an application, by server name and, when needed, destination network.
 */
class AdditionalServers implements ChildRecords
{
    public function key(): string
    {
        return 'additional_servers';
    }

    public function map(): FieldMap
    {
        return new FieldMap('additional_destinations', [
            Field::managed('server_id', 'additional_servers.*.server'),
            Field::managed('standalone_docker_id', 'additional_servers.*.destination'),
            Field::state('status', 'Runtime status of the containers on that server.'),
        ], ['additional_destinations' => ['application_id']]);
    }

    public function read(Model $owner): ?array
    {
        if (! $owner instanceof Application) {
            return null;
        }
        $result = [];
        foreach ($owner->additional_servers()->orderBy('servers.name')->get() as $server) {
            $entry = ['server' => $server->name];
            /** @var Server $server */
            if ($server->destinations()->count() > 1) {
                $entry['destination'] = StandaloneDocker::query()->find($server->pivot->standalone_docker_id)?->network;
            }
            $result[] = $entry;
        }

        return $result === [] ? null : $result;
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || $desired === []) {
            return null;
        }
        $entries = array_map(function ($entry) {
            $entry = is_string($entry) ? ['server' => $entry] : $entry;
            ksort($entry);

            return $entry;
        }, $desired);
        usort($entries, fn ($a, $b) => strcmp($a['server'], $b['server']));

        return $entries;
    }

    public function schema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => ['server'],
                'additionalProperties' => false,
                'properties' => ['server' => ['type' => 'string'], 'destination' => ['type' => 'string']],
            ],
        ];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        if (! $owner instanceof Application) {
            return;
        }
        $sync = [];
        foreach ((array) ($desired ?? []) as $entry) {
            $entry = is_string($entry) ? ['server' => $entry] : $entry;
            $server = Server::query()->where('team_id', $ctx->teamId)->get()->first(fn (Server $s) => $s->name === $entry['server'])
                ?? throw new LogicException("Server \"{$entry['server']}\" not found.");
            $destination = isset($entry['destination'])
                ? $server->standaloneDockers()->where('network', $entry['destination'])->first()
                : $server->standaloneDockers()->first();
            if ($destination === null) {
                throw new LogicException("No destination for server \"{$entry['server']}\".");
            }
            $sync[$server->id] = ['standalone_docker_id' => $destination->id];
        }
        $owner->additional_servers()->sync($sync);
    }
}
