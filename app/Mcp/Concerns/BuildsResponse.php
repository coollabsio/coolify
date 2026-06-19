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
        'hetzner_server_id', 'environment_id', 'destination_id',
        'source_id', 'repository_project_id', 'application_id',
        'service_id', 'project_id', 'parent_id',
        'resourceable', 'resourceable_id', 'resourceable_type',
        'destination_type', 'source_type', 'tokenable',

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

        // database connection strings embed credentials
        'internal_db_url', 'external_db_url', 'init_scripts',

        // webhook secrets
        'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea',
        'manual_webhook_secret_github', 'manual_webhook_secret_gitlab',

        // bulky / unsafe blobs
        'dockerfile', 'docker_compose', 'docker_compose_raw',
        'custom_labels', 'environment_variables',
        'environment_variables_preview', 'validation_logs',
        'server_metadata',
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
        $totalPages = (int) ceil($total / $perPage);

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
     * HATEOAS-style action suggestions for an application.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function actionsForApplication(string $uuid, ?string $status = null): array
    {
        $actions = [
            ['tool' => 'get_application', 'args' => ['uuid' => $uuid], 'hint' => 'Full details'],
        ];

        $s = strtolower((string) $status);
        if (str_contains($s, 'running')) {
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'application', 'action' => 'restart', 'uuid' => $uuid], 'hint' => 'Restart'];
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'application', 'action' => 'stop', 'uuid' => $uuid], 'hint' => 'Stop'];
        } else {
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'application', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start'];
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
        ];

        $s = strtolower((string) $status);
        if (str_contains($s, 'running')) {
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'database', 'action' => 'restart', 'uuid' => $uuid], 'hint' => 'Restart'];
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'database', 'action' => 'stop', 'uuid' => $uuid], 'hint' => 'Stop'];
        } else {
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'database', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start'];
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
        ];

        $s = strtolower((string) $status);
        if (str_contains($s, 'running')) {
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'service', 'action' => 'restart', 'uuid' => $uuid], 'hint' => 'Restart'];
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'service', 'action' => 'stop', 'uuid' => $uuid], 'hint' => 'Stop'];
        } else {
            $actions[] = ['tool' => 'control', 'args' => ['resource' => 'service', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start'];
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
        ];
    }
}
