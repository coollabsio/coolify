<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_application_logs')]
#[Description('Tail an application container\'s docker logs. If the container was recently auto-stopped and removed after exceeding its restart limit, falls back to a captured crash-log snapshot instead of an empty result.')]
class GetApplicationLogs extends Tool
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

        $applicationUuid = $request->get('application_uuid');
        if (! is_string($applicationUuid) || $applicationUuid === '') {
            return Response::error('application_uuid argument is required.');
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $applicationUuid)->first();
        if (! $application) {
            return Response::error("Application [{$applicationUuid}] not found.");
        }

        $pullRequestId = $request->get('pull_request_id');
        $pullRequestId = is_null($pullRequestId) ? 0 : (int) $pullRequestId;

        $lines = (int) ($request->get('lines') ?? self::DEFAULT_LINES);
        $lines = max(1, min(self::MAX_LINES, $lines));
        $timestamps = $request->get('timestamps');
        $timestamps = is_null($timestamps) ? true : (bool) $timestamps;

        $server = $application->destination->server;

        $result = [
            'application_uuid' => $applicationUuid,
            'status' => $application->status,
            'stopped_after_restart_limit' => $application->stoppedAfterRestartLimit(),
        ];

        if ($server && $server->isFunctional()) {
            $containers = getCurrentApplicationContainerStatus($server, $application->id, $pullRequestId, includePullrequests: $pullRequestId !== 0);
            $containerName = $containers->pluck('Names')->first();

            if ($containerName) {
                $cmd = ($server->isSwarm() ? 'docker service logs' : 'docker logs')." -n {$lines}".($timestamps ? ' -t' : '')." {$containerName}";
                $output = instant_remote_process([$cmd], $server, throwError: false);

                $result['source'] = 'live';
                $result['container'] = $containerName;
                $result['lines'] = $output === null || trim($output) === '' ? [] : explode("\n", removeAnsiColors($output));

                return $this->respond($result);
            }
        }

        // No live container — fall back to the last captured crash-log snapshot, if any.
        $snapshot = $application->last_crash_logs;
        if (! empty($snapshot)) {
            $result['source'] = 'crash_snapshot';
            $result['captured_at'] = $application->last_crash_logs_captured_at;
            $result['containers'] = collect($snapshot)->map(fn ($log, $containerName) => [
                'container' => $containerName,
                'lines' => explode("\n", $log),
            ])->values()->all();

            return $this->respond($result);
        }

        $result['source'] = null;
        $result['lines'] = [];
        $result['message'] = 'No live container found and no crash-log snapshot has been captured for this application.';

        return $this->respond($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'application_uuid' => $schema->string()->description('Application UUID.')->required(),
            'pull_request_id' => $schema->integer()->description('Pull request ID for preview deployments (default: production container).'),
            'lines' => $schema->integer()->description('Number of log lines to tail (default 100, max 1000).'),
            'timestamps' => $schema->boolean()->description('Include docker timestamps (default true).'),
        ];
    }
}
