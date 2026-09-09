<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetDeployment extends Tool
{
    protected string $name = 'get_deployment';

    protected string $description = 'Get deployment details by deployment UUID for the authenticated team. Optional include_log_summary requires read:sensitive and returns a capped, best-effort-redacted tail of build output (default off). Full logs and configuration snapshots are never returned; residual secrets in free-form log text may remain.';

    use BuildsResponse;
    use ResolvesTeam;

    private const DEFAULT_LOG_LINES = 40;

    private const MAX_LOG_LINES = 100;

    private const MAX_LOG_CHARS = 8000;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $includeSummary = filter_var($request->get('include_log_summary'), FILTER_VALIDATE_BOOLEAN);
        if ($includeSummary && $error = $this->ensureAbility($request, 'read:sensitive', $this->name)) {
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

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->first();
        if (! $deployment) {
            return $this->mcpError($request, "Deployment [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        // Include soft-deleted apps so mid-deploy deletes remain inspectable/cancellable.
        $application = $deployment->application()->withTrashed()->first();
        $appTeamId = $application?->team()?->id;
        if (! $application || (int) $appTeamId !== $teamId) {
            return $this->mcpError($request, "Deployment [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $data = $this->scrubSensitive([
            'deployment_uuid' => $deployment->deployment_uuid,
            'application_uuid' => $application->uuid,
            'application_name' => $deployment->application_name,
            'server_name' => $deployment->server_name,
            'status' => $deployment->status,
            'commit' => $deployment->commit,
            'commit_message' => $deployment->commit_message,
            'pull_request_id' => $deployment->pull_request_id,
            'force_rebuild' => $deployment->force_rebuild,
            'is_webhook' => $deployment->is_webhook,
            'is_api' => $deployment->is_api,
            'restart_only' => $deployment->restart_only,
            'rollback' => $deployment->rollback,
            'git_type' => $deployment->git_type,
            'deployment_url' => $deployment->deployment_url,
            'docker_registry_image_tag' => $deployment->docker_registry_image_tag,
            'created_at' => $deployment->created_at,
            'updated_at' => $deployment->updated_at,
            'finished_at' => $deployment->finished_at,
        ]);

        if ($includeSummary) {
            $lines = max(1, min(self::MAX_LOG_LINES, (int) ($request->get('log_lines') ?? self::DEFAULT_LOG_LINES)));
            $data['log_summary'] = $this->buildLogSummary($deployment, $lines);
        }

        return $this->mcpSuccess($request, $this->respond(
            $data,
            $this->actionsForDeployment($uuid, $application->uuid),
        ), ['resource_uuid' => $uuid]);
    }

    /**
     * @return array{available: bool, lines: int, truncated: bool, text: string|null}
     */
    private function buildLogSummary(ApplicationDeploymentQueue $deployment, int $lines): array
    {
        $raw = $deployment->getRawOriginal('logs') ?? $deployment->getAttributes()['logs'] ?? null;
        if (! is_string($raw) || $raw === '') {
            // logs may be hidden — force read from DB attribute bag
            $raw = $deployment->getAttributes()['logs'] ?? null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [
                'available' => false,
                'lines' => 0,
                'truncated' => false,
                'text' => null,
            ];
        }

        try {
            $entries = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $text = $this->redactLogText((string) $raw);
            $allLines = preg_split('/\r\n|\r|\n/', $text) ?: [$text];
            $tail = array_slice($allLines, -$lines);
            $text = implode("\n", $tail);
            $truncated = count($allLines) > $lines || strlen($text) > self::MAX_LOG_CHARS;
            $text = $this->truncateText($text, self::MAX_LOG_CHARS);

            return [
                'available' => true,
                'lines' => substr_count($text, "\n") + 1,
                'truncated' => $truncated,
                'text' => $text,
            ];
        }

        if (! is_array($entries)) {
            return [
                'available' => false,
                'lines' => 0,
                'truncated' => false,
                'text' => null,
            ];
        }

        $outputs = collect($entries)
            ->filter(fn ($e) => is_array($e) && ! ($e['hidden'] ?? false))
            ->map(function ($e) {
                $type = $e['type'] ?? 'stdout';
                $output = (string) ($e['output'] ?? '');
                $prefix = $type === 'stderr' || $type === 'error' ? '[err] ' : '';

                return $prefix.$this->redactLogText($output);
            })
            ->filter(fn ($line) => trim($line) !== '')
            ->values();

        $tail = $outputs->slice(max(0, $outputs->count() - $lines))->values();
        $text = $tail->implode("\n");
        $truncated = $outputs->count() > $lines || strlen($text) > self::MAX_LOG_CHARS;
        $text = $this->truncateText($text, self::MAX_LOG_CHARS);

        return [
            'available' => true,
            'lines' => $tail->count(),
            'truncated' => $truncated,
            'text' => $text,
        ];
    }

    private function truncateText(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, -$max);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Deployment UUID.')->required(),
            'include_log_summary' => $schema->boolean()->description('If true, include a capped redacted tail of deploy output; requires read:sensitive (default false).'),
            'log_lines' => $schema->integer()->description('Log summary lines when include_log_summary is true (default 40, max 100).'),
        ];
    }
}
