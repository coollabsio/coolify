<?php

namespace App\Services\DeploymentConfiguration;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Arr;

class ApplicationConfigurationSnapshot
{
    public const SCHEMA_VERSION = 1;

    public function __construct(protected Application $application) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->application->load('settings');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'resource_type' => Application::class,
            'resource_id' => $this->application->id,
            'sections' => [
                'source' => [
                    'label' => 'Source',
                    'items' => $this->sourceItems(),
                ],
                'build' => [
                    'label' => 'Build',
                    'items' => $this->buildItems(),
                ],
                'runtime' => [
                    'label' => 'Runtime',
                    'items' => $this->runtimeItems(),
                ],
                'domains' => [
                    'label' => 'Domains & Proxy',
                    'items' => $this->domainItems(),
                ],
                'environment' => [
                    'label' => 'Environment Variables',
                    'items' => $this->environmentItems(),
                ],
            ],
        ];
    }

    public function hash(): string
    {
        return self::hashSnapshot($this->toArray());
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function hashSnapshot(array $snapshot): string
    {
        return hash('sha256', json_encode(self::comparableSnapshot($snapshot), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function comparableSnapshot(array $snapshot): array
    {
        $sections = collect(data_get($snapshot, 'sections', []))
            ->mapWithKeys(function (array $section, string $sectionKey): array {
                $items = collect(data_get($section, 'items', []))
                    ->mapWithKeys(fn (array $item): array => [
                        $item['key'] => [
                            'compare_value' => $item['compare_value'] ?? null,
                            'impact' => $item['impact'] ?? 'redeploy',
                        ],
                    ])
                    ->sortKeys()
                    ->all();

                return [$sectionKey => $items];
            })
            ->sortKeys()
            ->all();

        return [
            'schema_version' => data_get($snapshot, 'schema_version'),
            'sections' => $sections,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceItems(): array
    {
        return [
            $this->item('git_repository', 'Repository', $this->application->git_repository, 'build'),
            $this->item('git_branch', 'Branch', $this->application->git_branch, 'build'),
            $this->item('git_commit_sha', 'Commit SHA', $this->application->git_commit_sha, 'build'),
            $this->item('private_key_id', 'Private key', $this->application->private_key_id, 'build'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(): array
    {
        return [
            $this->item('build_pack', 'Build pack', $this->application->build_pack, 'build'),
            $this->item('static_image', 'Static image', $this->application->static_image, 'build'),
            $this->item('base_directory', 'Base directory', $this->application->base_directory, 'build'),
            $this->item('publish_directory', 'Publish directory', $this->application->publish_directory, 'build'),
            $this->item('install_command', 'Install command', $this->application->install_command, 'build'),
            $this->item('build_command', 'Build command', $this->application->build_command, 'build'),
            $this->item('dockerfile', 'Dockerfile', $this->application->dockerfile, 'build', displayValue: $this->summarizeText($this->application->dockerfile)),
            $this->item('dockerfile_location', 'Dockerfile location', $this->application->dockerfile_location, 'build'),
            $this->item('dockerfile_target_build', 'Dockerfile target', $this->application->dockerfile_target_build, 'build'),
            $this->item('docker_compose_location', 'Docker Compose location', $this->application->docker_compose_location, 'build'),
            $this->item('docker_compose', 'Docker Compose', $this->application->docker_compose, 'build', displayValue: $this->summarizeText($this->application->docker_compose)),
            $this->item('docker_compose_raw', 'Raw Docker Compose', $this->application->docker_compose_raw, 'build', displayValue: $this->summarizeText($this->application->docker_compose_raw)),
            $this->item('docker_compose_custom_build_command', 'Docker Compose custom build command', $this->application->docker_compose_custom_build_command, 'build'),
            $this->item('custom_docker_run_options', 'Custom Docker run options', $this->application->custom_docker_run_options, 'build'),
            $this->item('use_build_secrets', 'Use build secrets', data_get($this->application, 'settings.use_build_secrets'), 'build'),
            $this->item('inject_build_args_to_dockerfile', 'Inject build args to Dockerfile', data_get($this->application, 'settings.inject_build_args_to_dockerfile'), 'build'),
            $this->item('include_source_commit_in_build', 'Include source commit in build', data_get($this->application, 'settings.include_source_commit_in_build'), 'build'),
            $this->item('disable_build_cache', 'Disable build cache', data_get($this->application, 'settings.disable_build_cache'), 'build'),
            $this->item('is_build_server_enabled', 'Build server', data_get($this->application, 'settings.is_build_server_enabled'), 'build'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runtimeItems(): array
    {
        return [
            $this->item('start_command', 'Start command', $this->application->start_command, 'redeploy'),
            $this->item('docker_compose_custom_start_command', 'Docker Compose custom start command', $this->application->docker_compose_custom_start_command, 'redeploy'),
            $this->item('ports_exposes', 'Exposed ports', $this->application->ports_exposes, 'redeploy'),
            $this->item('ports_mappings', 'Port mappings', $this->application->ports_mappings, 'redeploy'),
            $this->item('custom_network_aliases', 'Network aliases', $this->application->custom_network_aliases, 'redeploy'),
            $this->item('connect_to_docker_network', 'Connect to Docker network', data_get($this->application, 'settings.connect_to_docker_network'), 'redeploy'),
            $this->item('custom_internal_name', 'Custom container name', data_get($this->application, 'settings.custom_internal_name'), 'redeploy'),
            $this->item('is_raw_compose_deployment_enabled', 'Raw Compose deployment', data_get($this->application, 'settings.is_raw_compose_deployment_enabled'), 'redeploy'),
            $this->item('is_gpu_enabled', 'GPU enabled', data_get($this->application, 'settings.is_gpu_enabled'), 'redeploy'),
            $this->item('gpu_driver', 'GPU driver', data_get($this->application, 'settings.gpu_driver'), 'redeploy'),
            $this->item('gpu_count', 'GPU count', data_get($this->application, 'settings.gpu_count'), 'redeploy'),
            $this->item('gpu_device_ids', 'GPU device IDs', data_get($this->application, 'settings.gpu_device_ids'), 'redeploy'),
            $this->item('gpu_options', 'GPU options', data_get($this->application, 'settings.gpu_options'), 'redeploy'),
            ...$this->healthCheckItems(),
            ...$this->limitItems(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function domainItems(): array
    {
        return [
            $this->item('fqdn', 'Domains', $this->application->fqdn, 'redeploy'),
            $this->item('redirect', 'Redirect', $this->application->redirect, 'redeploy'),
            $this->item('custom_labels', 'Container labels', $this->application->custom_labels, 'redeploy', displayValue: $this->summarizeText($this->application->custom_labels)),
            $this->item('custom_nginx_configuration', 'Custom Nginx configuration', $this->application->custom_nginx_configuration, 'redeploy', displayValue: $this->summarizeText($this->application->custom_nginx_configuration)),
            $this->item('is_force_https_enabled', 'Force HTTPS', data_get($this->application, 'settings.is_force_https_enabled'), 'redeploy'),
            $this->item('is_gzip_enabled', 'Gzip', data_get($this->application, 'settings.is_gzip_enabled'), 'redeploy'),
            $this->item('is_stripprefix_enabled', 'Strip prefix', data_get($this->application, 'settings.is_stripprefix_enabled'), 'redeploy'),
            $this->item('is_http_basic_auth_enabled', 'HTTP basic auth', $this->application->is_http_basic_auth_enabled, 'redeploy'),
            $this->item('http_basic_auth_username', 'HTTP basic auth username', $this->application->http_basic_auth_username, 'redeploy'),
            $this->item('http_basic_auth_password', 'HTTP basic auth password', $this->application->http_basic_auth_password, 'redeploy', sensitive: true),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function environmentItems(): array
    {
        return $this->application->environment_variables()
            ->get()
            ->sortBy('key', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(fn (EnvironmentVariable $environmentVariable): array => $this->environmentItem($environmentVariable))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function healthCheckItems(): array
    {
        return collect([
            'health_check_enabled' => 'Health check enabled',
            'health_check_path' => 'Health check path',
            'health_check_port' => 'Health check port',
            'health_check_host' => 'Health check host',
            'health_check_method' => 'Health check method',
            'health_check_return_code' => 'Health check return code',
            'health_check_scheme' => 'Health check scheme',
            'health_check_response_text' => 'Health check response text',
            'health_check_interval' => 'Health check interval',
            'health_check_timeout' => 'Health check timeout',
            'health_check_retries' => 'Health check retries',
            'health_check_start_period' => 'Health check start period',
            'health_check_type' => 'Health check type',
            'health_check_command' => 'Health check command',
        ])->map(fn (string $label, string $key): array => $this->item($key, $label, data_get($this->application, $key), 'redeploy'))->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function limitItems(): array
    {
        return collect([
            'limits_memory' => 'Memory limit',
            'limits_memory_swap' => 'Memory swap limit',
            'limits_memory_swappiness' => 'Memory swappiness',
            'limits_memory_reservation' => 'Memory reservation',
            'limits_cpus' => 'CPU limit',
            'limits_cpuset' => 'CPU set',
            'limits_cpu_shares' => 'CPU shares',
            'swarm_replicas' => 'Swarm replicas',
            'swarm_placement_constraints' => 'Swarm placement constraints',
        ])->map(fn (string $label, string $key): array => $this->item($key, $label, data_get($this->application, $key), 'redeploy'))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentItem(EnvironmentVariable $environmentVariable): array
    {
        $impact = $environmentVariable->is_buildtime ? 'build' : 'redeploy';
        $compareValue = [
            'value_hash' => $this->sensitiveHash($environmentVariable->value),
            'is_multiline' => $environmentVariable->is_multiline,
            'is_literal' => $environmentVariable->is_literal,
            'is_buildtime' => $environmentVariable->is_buildtime,
            'is_runtime' => $environmentVariable->is_runtime,
        ];

        return $this->item(
            key: (string) $environmentVariable->key,
            label: (string) $environmentVariable->key,
            value: $compareValue,
            impact: $impact,
            sensitive: true,
            displayValue: $this->environmentDisplayValue($environmentVariable),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $key, string $label, mixed $value, string $impact, bool $sensitive = false, mixed $displayValue = null): array
    {
        $normalizedValue = $this->normalizeValue($value);

        return [
            'key' => $key,
            'label' => $label,
            'impact' => $impact,
            'sensitive' => $sensitive,
            'compare_value' => $sensitive ? $this->sensitiveHash($normalizedValue) : $normalizedValue,
            'display_value' => $displayValue ?? $this->displayValue($normalizedValue),
        ];
    }

    private function environmentDisplayValue(EnvironmentVariable $environmentVariable): string
    {
        $flags = collect([
            $environmentVariable->is_buildtime ? 'build-time' : null,
            $environmentVariable->is_runtime ? 'runtime' : null,
            $environmentVariable->is_multiline ? 'multiline' : null,
            $environmentVariable->is_literal ? 'literal' : null,
        ])->filter()->implode(', ');

        return $flags ? "Hidden ({$flags})" : 'Hidden';
    }

    private function sensitiveHash(mixed $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key', 'coolify'));
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === '') {
            return null;
        }

        if (is_bool($value) || is_numeric($value) || $value === null || is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return Arr::sortRecursive($value);
        }

        return (string) $value;
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null) {
            return 'Not set';
        }

        if (is_bool($value)) {
            return $value ? 'Enabled' : 'Disabled';
        }

        if (is_array($value)) {
            return $this->summarizeText(json_encode($value, JSON_THROW_ON_ERROR));
        }

        return $this->summarizeText((string) $value);
    }

    private function summarizeText(?string $value): string
    {
        if (blank($value)) {
            return 'Not set';
        }

        $value = trim((string) $value);
        $lines = substr_count($value, "\n") + 1;

        if ($lines > 1) {
            return str($value)->limit(80)." ({$lines} lines)";
        }

        return str($value)->limit(120)->value();
    }
}
