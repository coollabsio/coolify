<?php

namespace App\Services\Railway;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Service;
use App\Support\RailwayResourceMapper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The action surface the Railway assistant can call. Read tools return live
 * Coolify data; write tools (deploy_service / set_env_var) mutate real
 * resources and are gated behind explicit user confirmation in the UI.
 *
 * Every tool is scoped to a single Environment so the model can never reach
 * resources outside the one the user is looking at.
 */
final class AgentTools
{
    /** @var array<string, bool> tool name => is a mutating action */
    private const WRITE_TOOLS = [
        'deploy_service' => true,
        'set_env_var' => true,
    ];

    public function __construct(private readonly Environment $environment) {}

    public function isWrite(string $name): bool
    {
        return self::WRITE_TOOLS[$name] ?? false;
    }

    /**
     * Anthropic tool definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        $service = [
            'type' => 'string',
            'description' => 'The name or UUID of the resource in this environment.',
        ];

        return [
            [
                'name' => 'list_services',
                'description' => 'List every resource (application, database, service) in the current environment with its status.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass, 'required' => []],
            ],
            [
                'name' => 'get_service',
                'description' => 'Get details for one resource: kind, status, public URLs, and last deployment.',
                'input_schema' => ['type' => 'object', 'properties' => ['service' => $service], 'required' => ['service']],
            ],
            [
                'name' => 'get_env_vars',
                'description' => 'List the environment-variable keys defined on a resource. Values are never returned.',
                'input_schema' => ['type' => 'object', 'properties' => ['service' => $service], 'required' => ['service']],
            ],
            [
                'name' => 'get_deployments',
                'description' => 'List recent deployments for an application with status and commit.',
                'input_schema' => ['type' => 'object', 'properties' => ['service' => $service], 'required' => ['service']],
            ],
            [
                'name' => 'get_deployment_logs',
                'description' => 'Fetch the saved logs for a deployment (defaults to the most recent). Use this to diagnose why a deployment failed.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'service' => $service,
                        'deployment_id' => ['type' => 'integer', 'description' => 'Optional deployment id; omit for the latest.'],
                    ],
                    'required' => ['service'],
                ],
            ],
            [
                'name' => 'set_env_var',
                'description' => 'Create or update an environment variable on a resource. Requires user confirmation.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'service' => $service,
                        'key' => ['type' => 'string', 'description' => 'Variable name.'],
                        'value' => ['type' => 'string', 'description' => 'Variable value.'],
                    ],
                    'required' => ['service', 'key', 'value'],
                ],
            ],
            [
                'name' => 'deploy_service',
                'description' => 'Trigger a new deployment of an application. Requires user confirmation.',
                'input_schema' => ['type' => 'object', 'properties' => ['service' => $service], 'required' => ['service']],
            ],
        ];
    }

    /**
     * Execute a tool call.
     *
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error: bool}
     */
    public function execute(string $name, array $input): array
    {
        try {
            return match ($name) {
                'list_services' => $this->ok($this->listServices()),
                'get_service' => $this->getService((string) ($input['service'] ?? '')),
                'get_env_vars' => $this->getEnvVars((string) ($input['service'] ?? '')),
                'get_deployments' => $this->getDeployments((string) ($input['service'] ?? '')),
                'get_deployment_logs' => $this->getDeploymentLogs((string) ($input['service'] ?? ''), $input['deployment_id'] ?? null),
                'set_env_var' => $this->setEnvVar((string) ($input['service'] ?? ''), (string) ($input['key'] ?? ''), (string) ($input['value'] ?? '')),
                'deploy_service' => $this->deployService((string) ($input['service'] ?? '')),
                default => $this->err("Unknown tool: {$name}"),
            };
        } catch (AuthorizationException) {
            return $this->err('You are not authorized to perform this action.');
        } catch (\Throwable $e) {
            return $this->err('Tool failed: '.$e->getMessage());
        }
    }

    /** Human-readable summary of a pending write action, for the confirmation card. */
    public function summarize(string $name, array $input): string
    {
        return match ($name) {
            'deploy_service' => 'Deploy '.($input['service'] ?? 'service'),
            'set_env_var' => 'Set variable '.($input['key'] ?? '?').' on '.($input['service'] ?? 'service'),
            default => $name,
        };
    }

    // --- read tools -------------------------------------------------------

    private function listServices(): string
    {
        $rows = RailwayResourceMapper::resourcesFor($this->environment)->map(function (Model $r) {
            $status = (string) ($r->status ?? 'unknown');

            return sprintf('- %s [%s] status=%s', $r->name, RailwayResourceMapper::kind($r), $status ?: 'unknown');
        });

        if ($rows->isEmpty()) {
            return 'This environment has no resources yet.';
        }

        return "Resources in {$this->environment->name}:\n".$rows->implode("\n");
    }

    /** @return array{content: string, is_error: bool} */
    private function getService(string $ref): array
    {
        [$resource, $kind] = $this->resolve($ref);
        if (! $resource) {
            return $this->notFound($ref);
        }

        $lines = [
            "Name: {$resource->name}",
            "Kind: {$kind}",
            'Status: '.((string) ($resource->status ?? 'unknown') ?: 'unknown'),
            'UUID: '.$resource->uuid,
        ];

        if (filled($resource->fqdn ?? null)) {
            $lines[] = 'URLs: '.collect(explode(',', (string) $resource->fqdn))->map(fn ($f) => trim($f))->filter()->implode(', ');
        }

        if ($resource instanceof Application) {
            $last = $resource->get_last_successful_deployment();
            $lines[] = 'Last successful deployment: '.($last ? optional($last->created_at)->diffForHumans() : 'none');
        }

        return $this->ok(implode("\n", $lines));
    }

    /** @return array{content: string, is_error: bool} */
    private function getEnvVars(string $ref): array
    {
        [$resource] = $this->resolve($ref);
        if (! $resource) {
            return $this->notFound($ref);
        }
        if (! method_exists($resource, 'environment_variables')) {
            return $this->err("Resource {$resource->name} has no environment variables.");
        }

        $keys = $resource->environment_variables()->orderBy('key')->pluck('key')->filter()->values();
        if ($keys->isEmpty()) {
            return $this->ok("No environment variables set on {$resource->name}.");
        }

        return $this->ok("Environment variable keys on {$resource->name} (values hidden):\n".$keys->map(fn ($k) => "- {$k}")->implode("\n"));
    }

    /** @return array{content: string, is_error: bool} */
    private function getDeployments(string $ref): array
    {
        [$resource] = $this->resolve($ref);
        if (! ($resource instanceof Application)) {
            return $this->err('Only applications have deployments.');
        }

        $result = $resource->deployments(0, 10);
        $deployments = $result['deployments'] ?? collect();
        if ($deployments->isEmpty()) {
            return $this->ok("No deployments recorded for {$resource->name}.");
        }

        $rows = $deployments->map(fn ($d) => sprintf(
            '- #%d status=%s %s %s',
            $d->id,
            $d->status,
            $d->commit ? substr((string) $d->commit, 0, 7) : '',
            optional($d->created_at)->diffForHumans() ?? '',
        ));

        return $this->ok("Recent deployments for {$resource->name}:\n".$rows->implode("\n"));
    }

    /** @return array{content: string, is_error: bool} */
    private function getDeploymentLogs(string $ref, int|string|null $deploymentId): array
    {
        [$resource] = $this->resolve($ref);
        if (! ($resource instanceof Application)) {
            return $this->err('Only applications have deployment logs.');
        }

        $query = ApplicationDeploymentQueue::query()->where('application_id', $resource->id);
        $deployment = $deploymentId
            ? $query->where('id', (int) $deploymentId)->first()
            : $query->orderByDesc('id')->first();

        if (! $deployment) {
            return $this->err('No matching deployment found.');
        }

        $lines = collect(is_string($deployment->logs) ? (json_decode($deployment->logs, true) ?: []) : (is_array($deployment->logs) ? $deployment->logs : []))
            ->reject(fn ($e) => (bool) data_get($e, 'hidden', false))
            ->map(fn ($e) => (string) data_get($e, 'output', ''))
            ->filter()
            ->values();

        // Keep the tail; deploy logs can be long and we only need the failure context.
        $tail = $lines->count() > 120 ? $lines->slice($lines->count() - 120)->values() : $lines;

        $header = "Deployment #{$deployment->id} status={$deployment->status}";
        if ($tail->isEmpty()) {
            return $this->ok("{$header}\n(no log output stored)");
        }

        return $this->ok("{$header}\n".$tail->implode("\n"));
    }

    // --- write tools (confirmed) -----------------------------------------

    /** @return array{content: string, is_error: bool} */
    private function setEnvVar(string $ref, string $key, string $value): array
    {
        if ($key === '') {
            return $this->err('A variable key is required.');
        }

        [$resource] = $this->resolve($ref);
        if (! $resource) {
            return $this->notFound($ref);
        }
        if (! method_exists($resource, 'environment_variables')) {
            return $this->err("Resource {$resource->name} does not support environment variables.");
        }

        Gate::authorize('update', $resource);

        $existing = $resource->environment_variables()->where('key', $key)->first();
        if ($existing) {
            $existing->value = $value;
            $existing->save();

            return $this->ok("Updated variable {$key} on {$resource->name}.");
        }

        $resource->environment_variables()->create(['key' => $key, 'value' => $value]);

        return $this->ok("Created variable {$key} on {$resource->name}.");
    }

    /** @return array{content: string, is_error: bool} */
    private function deployService(string $ref): array
    {
        [$resource] = $this->resolve($ref);
        if (! ($resource instanceof Application)) {
            return $this->err('Only applications can be deployed from here.');
        }

        Gate::authorize('deploy', $resource);

        $server = $resource->destination?->server;
        $destination = $resource->destination;
        if (! $server || ! $destination) {
            return $this->err("{$resource->name} has no server/destination configured.");
        }

        $uuid = new_public_id();
        $result = queue_application_deployment(
            deployment_uuid: $uuid,
            application: $resource,
            server: $server,
            destination: $destination,
            only_this_server: true,
            no_questions_asked: true,
        );

        return match ($result['status'] ?? 'queued') {
            'queue_full' => $this->err('Deployment queue is full: '.($result['message'] ?? '')),
            'skipped' => $this->ok('Deployment skipped: '.($result['message'] ?? '')),
            default => $this->ok("Queued a deployment of {$resource->name} (deployment {$uuid})."),
        };
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Resolve a service reference (name or uuid) within this environment.
     *
     * @return array{0: ?Model, 1: string}
     */
    private function resolve(string $ref): array
    {
        $ref = trim($ref);
        $match = RailwayResourceMapper::resourcesFor($this->environment)->first(
            fn (Model $r) => $r->uuid === $ref || strcasecmp((string) $r->name, $ref) === 0,
        );

        return [$match, $match ? RailwayResourceMapper::kind($match) : ''];
    }

    /** @return array{content: string, is_error: bool} */
    private function notFound(string $ref): array
    {
        return $this->err("No resource named '{$ref}' in this environment. Call list_services to see valid names.");
    }

    /** @return array{content: string, is_error: bool} */
    private function ok(string $content): array
    {
        return ['content' => $content, 'is_error' => false];
    }

    /** @return array{content: string, is_error: bool} */
    private function err(string $content): array
    {
        return ['content' => $content, 'is_error' => true];
    }
}
