<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait BuildsResponse
{
    protected int $defaultPerPage = 50;

    protected int $maxPerPage = 100;

    /**
     * Keys removed at any depth from get_* responses.
     *
     * Covers: raw integer surrogate keys (id and *_id columns; uuid stays),
     * Eloquent morph types, encrypted secrets, DB passwords, and bulky
     * payloads that should never traverse the MCP boundary.
     *
     * @var array<int, string>
     */
    protected array $sensitiveKeys = [
        // raw IDs / morph types (uuid is the public identifier)
        'id', 'team_id', 'tokenable_id', 'tokenable_type',
        'server_id', 'private_key_id', 'cloud_provider_token_id',
        'hetzner_server_id', 'digitalocean_droplet_id', 'environment_id', 'destination_id',
        'source_id', 'repository_project_id', 'application_id',
        'service_id', 'project_id', 'parent_id',
        'resourceable', 'resourceable_id', 'resourceable_type',
        'destination_type', 'source_type', 'tokenable',
        'build_server_id', 'horizon_job_id', 'horizon_job_worker',
        'current_process_id', 'app_id', 'installation_id',

        // sentinel / observability secrets
        'sentinel_token', 'sentinel_custom_url',
        'logdrain_newrelic_license_key', 'logdrain_axiom_api_key',
        'logdrain_custom_config', 'logdrain_custom_config_parser',

        // database passwords
        'postgres_password', 'dragonfly_password', 'keydb_password',
        'redis_password', 'mongo_initdb_root_password',
        'mariadb_password', 'mariadb_root_password',
        'mysql_password', 'mysql_root_password',
        'clickhouse_admin_password',

        // app/env secrets
        'value', 'real_value', 'http_basic_auth_password',

        // free-form commands / configurations can embed credentials
        'git_full_url',
        'install_command', 'build_command', 'start_command',
        'health_check_command', 'health_check_response_text',
        'custom_docker_run_options', 'pre_deployment_command', 'post_deployment_command',
        'docker_compose_custom_start_command', 'docker_compose_custom_build_command',
        'custom_nginx_configuration',

        // raw database configuration blobs
        'postgres_conf', 'mysql_conf', 'mariadb_conf', 'mongo_conf',
        'redis_conf', 'keydb_conf',

        // database connection strings embed credentials
        'internal_db_url', 'external_db_url', 'init_scripts',

        // webhook / oauth / key secrets
        'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea',
        'manual_webhook_secret_github', 'manual_webhook_secret_gitlab',
        'client_secret', 'webhook_secret', 'client_id',
        'private_key', 'public_key',

        // bulky / unsafe blobs
        'dockerfile', 'docker_compose', 'docker_compose_raw',
        'last_saved_proxy_configuration',
        'custom_labels', 'environment_variables',
        'environment_variables_preview', 'validation_logs',
        'server_metadata', 'logs', 'configuration_snapshot',
        'configuration_diff', 'fs_path', 'content', 'file_storage_content',
    ];

    /**
     * Recursively remove sensitive keys from any nested array structure.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function scrubSensitive(array $data): array
    {
        $deny = array_flip($this->sensitiveKeys);

        $walk = function ($value) use (&$walk, $deny) {
            if (! is_array($value)) {
                return $value;
            }

            $out = [];
            foreach ($value as $key => $inner) {
                if (is_string($key) && isset($deny[$key])) {
                    continue;
                }
                $out[$key] = $walk($inner);
            }

            return $out;
        };

        return $walk($data);
    }

    /**
     * Best-effort redaction for free-form log text shown to MCP clients.
     * Uses remove_iip plus common KEY=value / JSON secret patterns; not a guarantee.
     */
    protected function redactLogText(string $text): string
    {
        $text = remove_iip($text);

        // password= / secret= / token= / "token":"..." style (shell or JSON; quoted or bare)
        $text = preg_replace(
            '/(?<![\w])["\']?(password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|auth[_-]?token)["\']?\s*[=:]\s*["\']?[^\s"\']{3,}["\']?/i',
            '$1='.REDACTED,
            $text
        ) ?? $text;

        // export FOO=bar / "API_KEY":"..." style for sensitive-looking names
        $text = preg_replace(
            '/(?<![\w])(export\s+)?["\']?([A-Z][A-Z0-9_]*(?:SECRET|PASSWORD|TOKEN|PASSWD|API_KEY|PRIVATE_KEY)[A-Z0-9_]*)["\']?\s*[=:]\s*["\']?[^\s"\']{3,}["\']?/i',
            '$1$2='.REDACTED,
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $data
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<string, mixed>|null  $pagination
     */
    protected function respond(array $data, array $actions = [], ?array $pagination = null): Response
    {
        $payload = ['data' => $data];

        if ($actions !== []) {
            $payload['_actions'] = $actions;
        }

        if ($pagination !== null) {
            $payload['_pagination'] = $pagination;
        }

        return Response::json($payload);
    }

    /**
     * @return array{page:int, per_page:int, offset:int}
     */
    protected function paginationArgs(Request $request): array
    {
        $page = max(1, (int) ($request->get('page') ?? 1));
        $perPage = (int) ($request->get('per_page') ?? $this->defaultPerPage);
        $perPage = max(1, min($this->maxPerPage, $perPage));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    /**
     * @param  array{page:int, per_page:int, offset:int}  $args
     * @return array<string, mixed>|null
     */
    protected function paginationMeta(string $tool, array $args, int $total, array $extraArgs = []): ?array
    {
        $page = $args['page'];
        $perPage = $args['per_page'];
        $totalPages = (int) ceil($total / max(1, $perPage));

        $meta = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];

        if ($page < $totalPages) {
            $meta['next'] = [
                'tool' => $tool,
                'args' => array_merge($extraArgs, ['page' => $page + 1, 'per_page' => $perPage]),
            ];
        }

        return $meta;
    }

    /**
     * HATEOAS-style action suggestions for an application (read tools only).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function actionsForApplication(string $uuid, ?string $status = null): array
    {
        $actions = [
            ['tool' => 'get_application', 'args' => ['uuid' => $uuid], 'hint' => 'Full details'],
            ['tool' => 'list_env_keys', 'args' => ['resource' => 'application', 'uuid' => $uuid], 'hint' => 'Env key names (no values)'],
            ['tool' => 'list_deployments', 'args' => ['application_uuid' => $uuid], 'hint' => 'Deployment history'],
            ['tool' => 'list_application_previews', 'args' => ['uuid' => $uuid], 'hint' => 'PR preview deployments'],
            ['tool' => 'list_storages', 'args' => ['resource' => 'application', 'uuid' => $uuid], 'hint' => 'Volumes / file mounts'],
            ['tool' => 'list_scheduled_tasks', 'args' => ['resource' => 'application', 'uuid' => $uuid], 'hint' => 'Scheduled tasks'],
        ];

        $s = strtolower((string) $status);
        // Match GetLogs/control: any running* status (including unhealthy) can fetch logs / restart / stop.
        if (str_starts_with($s, 'running')) {
            $actions[] = ['tool' => 'get_logs', 'args' => ['resource' => 'application', 'uuid' => $uuid], 'hint' => 'Live container logs (requires running)'];
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'application', 'action' => 'restart', 'uuid' => $uuid], 'hint' => 'Restart (needs deploy ability)'];
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'application', 'action' => 'stop', 'uuid' => $uuid, 'confirm' => true], 'hint' => 'Stop (needs deploy + confirm)'];
        } else {
            $actions[] = ['tool' => 'deploy', 'args' => ['uuid' => $uuid], 'hint' => 'Deploy/start (needs deploy ability)'];
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'application', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start (needs deploy ability)'];
        }

        return $actions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function actionsForDatabase(string $uuid, ?string $status = null): array
    {
        $actions = [
            ['tool' => 'get_database', 'args' => ['uuid' => $uuid], 'hint' => 'Full details'],
            ['tool' => 'list_env_keys', 'args' => ['resource' => 'database', 'uuid' => $uuid], 'hint' => 'Env key names (no values)'],
            ['tool' => 'list_database_backups', 'args' => ['uuid' => $uuid], 'hint' => 'Backup schedules'],
            ['tool' => 'list_storages', 'args' => ['resource' => 'database', 'uuid' => $uuid], 'hint' => 'Volumes / file mounts'],
        ];

        $s = strtolower((string) $status);
        // Control treats any status containing "running" as already started (including unhealthy).
        // Suggest logs/restart for those; only non-running statuses get start.
        if (str_starts_with($s, 'running')) {
            $actions[] = ['tool' => 'get_logs', 'args' => ['resource' => 'database', 'uuid' => $uuid], 'hint' => 'Live logs if running'];
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'database', 'action' => 'restart', 'uuid' => $uuid], 'hint' => 'Restart (needs deploy ability)'];
        } else {
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'database', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start (needs deploy ability)'];
        }

        return $actions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function actionsForService(string $uuid, ?string $status = null): array
    {
        $actions = [
            ['tool' => 'get_service', 'args' => ['uuid' => $uuid], 'hint' => 'Full details'],
            ['tool' => 'list_service_applications', 'args' => ['uuid' => $uuid], 'hint' => 'Service applications'],
            ['tool' => 'list_service_databases', 'args' => ['uuid' => $uuid], 'hint' => 'Service databases'],
            ['tool' => 'list_env_keys', 'args' => ['resource' => 'service', 'uuid' => $uuid], 'hint' => 'Env key names (no values)'],
            ['tool' => 'list_storages', 'args' => ['resource' => 'service', 'uuid' => $uuid], 'hint' => 'Volumes / file mounts'],
            ['tool' => 'list_scheduled_tasks', 'args' => ['resource' => 'service', 'uuid' => $uuid], 'hint' => 'Scheduled tasks'],
        ];

        $s = strtolower((string) $status);
        // Control treats any status containing "running" as already started (including unhealthy).
        // Suggest logs/restart for those; only non-running statuses get start.
        if (str_contains($s, 'running')) {
            $actions[] = ['tool' => 'get_logs', 'args' => ['resource' => 'service', 'uuid' => $uuid], 'hint' => 'Live logs if running'];
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'service', 'action' => 'restart', 'uuid' => $uuid], 'hint' => 'Restart (needs deploy ability)'];
        } else {
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'service', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start (needs deploy ability)'];
        }

        return $actions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function actionsForServer(string $uuid): array
    {
        return [
            ['tool' => 'get_server', 'args' => ['uuid' => $uuid], 'hint' => 'Full details'],
            ['tool' => 'get_server_domains', 'args' => ['uuid' => $uuid], 'hint' => 'Domains on this server'],
            ['tool' => 'get_server_resources', 'args' => ['uuid' => $uuid], 'hint' => 'Resources on this server'],
            ['tool' => 'list_destinations', 'args' => ['server_uuid' => $uuid], 'hint' => 'Docker destinations'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function actionsForProject(string $uuid): array
    {
        return [
            ['tool' => 'get_project', 'args' => ['uuid' => $uuid], 'hint' => 'Project details'],
            ['tool' => 'list_applications', 'args' => ['project_uuid' => $uuid], 'hint' => 'Applications in project'],
            ['tool' => 'list_services', 'args' => ['project_uuid' => $uuid], 'hint' => 'Services in project'],
            ['tool' => 'list_databases', 'args' => ['project_uuid' => $uuid], 'hint' => 'Databases in project'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function actionsForDeployment(string $deploymentUuid, ?string $applicationUuid = null): array
    {
        $actions = [
            ['tool' => 'get_deployment', 'args' => ['uuid' => $deploymentUuid], 'hint' => 'Deployment details'],
        ];

        if (is_string($applicationUuid) && $applicationUuid !== '') {
            $actions[] = ['tool' => 'get_application', 'args' => ['uuid' => $applicationUuid], 'hint' => 'Parent application'];
            $actions[] = ['tool' => 'get_logs', 'args' => ['resource' => 'application', 'uuid' => $applicationUuid], 'hint' => 'Application logs'];
        }

        return $actions;
    }
}
