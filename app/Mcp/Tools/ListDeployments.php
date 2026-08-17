<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListDeployments extends Tool
{
    protected string $name = 'list_deployments';

    protected string $description = 'List deployments for the authenticated team. Without application_uuid, returns in_progress/queued deployments. With application_uuid, returns that app\'s deployment history (paginated). Build logs are never included.';

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

        $applicationUuid = $request->get('application_uuid');
        if ($applicationUuid !== null && (! is_string($applicationUuid) || $applicationUuid === '')) {
            return $this->mcpError($request, 'application_uuid must be a non-empty string.');
        }

        $args = $this->paginationArgs($request);

        if (is_string($applicationUuid)) {
            $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $applicationUuid)->first();
            if (! $application) {
                return $this->mcpError($request, "Application [{$applicationUuid}] not found.", ['resource_uuid' => $applicationUuid]);
            }

            $query = ApplicationDeploymentQueue::query()
                ->where('application_id', $application->id)
                ->orderByDesc('created_at');

            $total = (clone $query)->count();
            $rows = $query->skip($args['offset'])->take($args['per_page'])->get();

            $summaries = $rows->map(fn ($d) => $this->summarizeDeployment($d, $application->uuid))->values()->all();

            return $this->mcpSuccess($request, $this->respond(
                $summaries,
                [],
                $this->paginationMeta('list_deployments', $args, $total, ['application_uuid' => $applicationUuid]),
            ));
        }

        $status = $request->get('status');
        $statuses = ['in_progress', 'queued'];
        if (is_string($status) && $status !== '' && $status !== 'all') {
            $statuses = [$status];
        } elseif ($status === 'all') {
            $statuses = null;
        }

        // application_deployment_queues.application_id is varchar; whereHas joins to
        // applications.id (bigint) and breaks on PostgreSQL. Scope via string IDs instead.
        $teamApplicationIds = Application::ownedByCurrentTeamAPI($teamId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        $query = ApplicationDeploymentQueue::query()
            ->with('application:id,uuid')
            ->whereIn('application_id', $teamApplicationIds)
            ->orderByDesc('id');

        if (is_array($statuses)) {
            $query->whereIn('status', $statuses);
        }

        $total = (clone $query)->count();
        $rows = $query->skip($args['offset'])->take($args['per_page'])->get();

        $summaries = $rows->map(function ($d) {
            $appUuid = $d->application?->uuid;

            return $this->summarizeDeployment($d, $appUuid);
        })->values()->all();

        $extra = array_filter(['status' => is_string($status) ? $status : null]);

        return $this->mcpSuccess($request, $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_deployments', $args, $total, $extra),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeDeployment(ApplicationDeploymentQueue $deployment, ?string $applicationUuid): array
    {
        return $this->scrubSensitive([
            'deployment_uuid' => $deployment->deployment_uuid,
            'application_uuid' => $applicationUuid,
            'application_name' => $deployment->application_name,
            'server_name' => $deployment->server_name,
            'status' => $deployment->status,
            'commit' => $deployment->commit,
            'commit_message' => $deployment->commit_message,
            'pull_request_id' => $deployment->pull_request_id,
            'force_rebuild' => $deployment->force_rebuild,
            'is_webhook' => $deployment->is_webhook,
            'is_api' => $deployment->is_api,
            'deployment_url' => $deployment->deployment_url,
            'created_at' => $deployment->created_at,
            'updated_at' => $deployment->updated_at,
            'finished_at' => $deployment->finished_at,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'application_uuid' => $schema->string()->description('Optional application UUID for deployment history.'),
            'status' => $schema->string()->description('Optional status filter when not using application_uuid: in_progress, queued, finished, failed, or all.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
