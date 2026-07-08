<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Service;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_service_container_logs')]
#[Description('Tail the docker logs of a single container belonging to a service stack. Default 100 lines, max 1000.')]
class GetServiceContainerLogs extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    private const DEFAULT_LINES = 100;

    private const MAX_LINES = 1000;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $container = $request->get('container');
        if (! is_string($container) || $container === '') {
            return Response::error('container argument is required.');
        }

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)
            ->where('uuid', $uuid)
            ->first();

        if (! $service) {
            return Response::error("Service [{$uuid}] not found.");
        }

        $validContainerNames = $service->applications()->get()->map(fn ($a) => "{$a->name}-{$service->uuid}")
            ->concat($service->databases()->get()->map(fn ($d) => "{$d->name}-{$service->uuid}"));

        if (! $validContainerNames->contains($container) || ! ValidationPatterns::isValidContainerName($container)) {
            return Response::error("Container [{$container}] does not belong to service [{$uuid}].");
        }

        $server = $service->server;
        if (! $server || ! $server->isFunctional()) {
            return Response::error('Server is not functional.');
        }

        $lines = (int) ($request->get('lines') ?? self::DEFAULT_LINES);
        $lines = max(1, min(self::MAX_LINES, $lines));
        $timestamps = $request->get('timestamps');
        $timestamps = is_null($timestamps) ? true : (bool) $timestamps;

        $cmd = ($server->isSwarm() ? 'docker service logs' : 'docker logs')." -n {$lines}".($timestamps ? ' -t' : '')." {$container}";

        $output = instant_remote_process([$cmd], $server, throwError: false);

        $lineList = $output === null || trim($output) === ''
            ? []
            : explode("\n", removeAnsiColors($output));

        return $this->respond([
            'service_uuid' => $uuid,
            'container' => $container,
            'lines' => $lineList,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Service UUID.')->required(),
            'container' => $schema->string()->description('Container name, as returned by list_service_containers.')->required(),
            'lines' => $schema->integer()->description('Number of log lines to tail (default 100, max 1000).'),
            'timestamps' => $schema->boolean()->description('Include docker timestamps (default true).'),
        ];
    }
}
