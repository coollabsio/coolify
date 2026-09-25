<?php

namespace App\Services;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ComposeBindPathResolver
{
    public static function resolve(LocalFileVolume $volume, ?string $composeFile = null, ?string $envFile = null, ?string $projectDirectory = null): string
    {
        $resource = $volume->resource;
        if ($resource instanceof Application) {
            $workdir = $resource->workdir();
            $server = $resource->destination->server;
            $composeFile ??= $workdir.'/docker-compose.yaml';
            if ($resource->docker_compose_custom_start_command) {
                throw new RuntimeException('Cannot resolve storage from a custom Compose start command.');
            }
            $serviceName = null;
            $projectName = $resource->settings->is_raw_compose_deployment_enabled ? null : $resource->uuid;
            if (! $resource->settings->is_raw_compose_deployment_enabled && ! $resource->settings->is_preserve_repository_enabled) {
                /** The helper container uses a deployment-specific project directory for `up`. */
                if ($projectDirectory === null) {
                    $deployment = $resource->deployment_queue()
                        ->where('status', ApplicationDeploymentStatus::FINISHED->value)
                        ->where('pull_request_id', 0)
                        ->where('server_id', $server->id)
                        ->where('restart_only', false)
                        ->latest('id')
                        ->first();
                    if ($deployment === null) {
                        throw new RuntimeException('No completed Compose deployment is available to resolve this bind source.');
                    }
                    $projectDirectory = $resource->generateBaseDir($deployment->deployment_uuid)
                        .rtrim((string) $resource->base_directory, '/');
                }
            }
        } elseif ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            $workdir = $resource->service->workdir();
            $server = $resource->service->server;
            $composeFile ??= $workdir.'/docker-compose.yml';
            $serviceName = $resource->name;
            $projectName = $resource->service->uuid;
        } else {
            throw new RuntimeException('Cannot resolve storage for this resource.');
        }

        $projectDirectory ??= $workdir;
        if ($resource instanceof Application && $envFile === null) {
            $mainEnvFile = $workdir.'/.env-main';
            if (instant_remote_process(['test -f '.escapeshellarg($mainEnvFile).' && echo OK || echo NOK'], $server) === 'OK') {
                $envFile = $mainEnvFile;
            } else {
                $latest = $resource->deployment_queue()
                    ->where('server_id', $server->id)
                    ->latest('id')
                    ->first();
                if ($latest !== null && $latest->pull_request_id !== 0) {
                    throw new RuntimeException('The main Compose environment file is unavailable after a preview deployment. Redeploy the main application.');
                }
            }
        }
        $envFile ??= $workdir.'/.env';
        /** Source files for service `env_file` can disappear with the helper; they do not supply bind interpolation. */
        $command = 'docker compose --project-directory '.escapeshellarg($projectDirectory)
            .($projectName === null ? '' : ' --project-name '.escapeshellarg($projectName))
            .' -f '.escapeshellarg($composeFile)
            .' --env-file '.escapeshellarg($envFile)
            .' config --format json'
            .($projectDirectory === $workdir ? '' : ' --no-env-resolution');
        $output = instant_remote_process([$command], $server);
        $config = json_decode((string) $output, true);
        if (! is_array($config)) {
            $config = Yaml::parse((string) $output);
        }

        return self::pathFromConfig($config, $serviceName, $volume->mount_path);
    }

    public static function pathFromConfig(mixed $config, ?string $serviceName, string $target): string
    {
        if (! is_array($config) || ! is_array($config['services'] ?? null)) {
            throw new RuntimeException('Docker Compose did not return a valid configuration.');
        }

        $matches = [];
        foreach ($config['services'] as $name => $service) {
            if ($serviceName !== null && $name !== $serviceName) {
                continue;
            }
            foreach ($service['volumes'] ?? [] as $mount) {
                if (($mount['target'] ?? null) === $target) {
                    $matches[] = $mount;
                }
            }
        }
        if (count($matches) !== 1 || ($matches[0]['type'] ?? null) !== 'bind') {
            throw new RuntimeException('Storage target does not identify one Compose bind mount.');
        }

        $path = $matches[0]['source'] ?? null;
        if (! is_string($path) || $path === '' || ! str_starts_with($path, '/')) {
            throw new RuntimeException('Docker Compose returned an invalid bind source.');
        }
        $path = normalizeUnixPath($path, allowLiteralBindCharacters: true);
        if ($path === '/' || $path === '') {
            throw new RuntimeException('Docker Compose returned a root-dangerous bind source.');
        }

        return $path;
    }
}
