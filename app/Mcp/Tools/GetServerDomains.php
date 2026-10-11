<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Stringable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetServerDomains extends Tool
{
    protected string $name = 'get_server_domains';

    protected string $description = 'List domains hosted on a server owned by the authenticated team.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $server = Server::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $server) {
            return $this->mcpError($request, "Server [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $standaloneDockerIds = $server->standaloneDockers()->pluck('id');
        $swarmDockerIds = $server->swarmDockers()->pluck('id');

        $applications = Application::ownedByCurrentTeamAPI($teamId)
            ->where(function ($query) use ($standaloneDockerIds, $swarmDockerIds) {
                $query->where(function ($q) use ($standaloneDockerIds) {
                    $q->where('destination_type', StandaloneDocker::class)
                        ->whereIn('destination_id', $standaloneDockerIds);
                })->orWhere(function ($q) use ($swarmDockerIds) {
                    $q->where('destination_type', SwarmDocker::class)
                        ->whereIn('destination_id', $swarmDockerIds);
                });
            })
            ->get(['uuid', 'name', 'fqdn']);

        $domains = collect();

        foreach ($applications as $application) {
            $fqdn = str($application->fqdn)->explode(',')->map(fn ($fqdn) => self::domainHostWithoutPort((string) $fqdn))->filter(fn (Stringable $f) => $f->isNotEmpty());

            if ($fqdn->isNotEmpty()) {
                $domains->push([
                    'resource_type' => 'application',
                    'resource_uuid' => $application->uuid,
                    'resource_name' => $application->name,
                    'domains' => $fqdn->map(fn ($d) => (string) $d)->values()->all(),
                ]);
            }
        }

        return $this->mcpSuccess($request, $this->respond([
            'server_uuid' => $server->uuid,
            'domains' => $domains->values()->all(),
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Server UUID.')->required(),
        ];
    }

    /**
     * The host of a domain without the port: "[2a01:4f8::1]:8080" gives "[2a01:4f8::1]", "example.com:3000" gives "example.com".
     */
    private static function domainHostWithoutPort(string $fqdn): Stringable
    {
        $authority = (string) str($fqdn)->replace('http://', '')->replace('https://', '')->explode('/')[0];
        if (str_starts_with($authority, '[') && str_contains($authority, ']')) {
            return str(substr($authority, 0, strpos($authority, ']') + 1));
        }

        return str(str($authority)->explode(':')[0]);
    }
}
