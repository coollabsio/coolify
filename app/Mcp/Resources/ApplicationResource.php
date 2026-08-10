<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

class ApplicationResource extends Resource implements HasUriTemplate
{
    use BuildsResponse;
    use ResolvesTeam;

    protected string $name = 'coolify-application';

    protected string $description = 'Application summary JSON for a team-owned application UUID (coolify://application/{uuid}).';

    protected string $mimeType = 'application/json';

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('coolify://application/{uuid}');
    }

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid is required in resource URI.');
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)
            ->with(['environment.project:id,uuid,name,team_id'])
            ->where('uuid', $uuid)
            ->first();

        if (! $application) {
            return Response::error("Application [{$uuid}] not found.");
        }

        $payload = $this->scrubSensitive([
            'uuid' => $application->uuid,
            'name' => $application->name,
            'status' => $application->status,
            'fqdn' => $application->fqdn,
            'git_repository' => $application->git_repository,
            'git_branch' => $application->git_branch,
            'build_pack' => $application->build_pack,
            'project_uuid' => $application->environment?->project?->uuid,
            'project_name' => $application->environment?->project?->name,
            'environment_uuid' => $application->environment?->uuid,
            'environment_name' => $application->environment?->name,
        ]);

        return Response::text(json_encode($payload, JSON_PRETTY_PRINT));
    }
}
