<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListApplicationPreviews extends Tool
{
    protected string $name = 'list_application_previews';

    protected string $description = 'List pull-request preview deployments for an application owned by the authenticated team.';

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

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return $this->mcpError($request, "Application [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $args = $this->paginationArgs($request);
        $query = $application->previews()->orderByDesc('pull_request_id');
        $total = (clone $query)->count();

        $previews = $query
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($preview) => $this->scrubSensitive([
                'uuid' => $preview->uuid,
                'pull_request_id' => $preview->pull_request_id,
                'pull_request_html_url' => $preview->pull_request_html_url,
                'fqdn' => $preview->fqdn,
                'status' => $preview->status,
                'git_type' => $preview->git_type,
                'docker_registry_image_tag' => $preview->docker_registry_image_tag,
                'last_online_at' => $preview->last_online_at,
                'created_at' => $preview->created_at,
                'updated_at' => $preview->updated_at,
            ]))
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond(
            [
                'application_uuid' => $uuid,
                'previews' => $previews,
            ],
            [
                ['tool' => 'get_application', 'args' => ['uuid' => $uuid], 'hint' => 'Parent application'],
                ['tool' => 'list_deployments', 'args' => ['application_uuid' => $uuid], 'hint' => 'Deployments (includes PR deploys)'],
            ],
            $this->paginationMeta('list_application_previews', $args, $total, ['uuid' => $uuid]),
        ), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
