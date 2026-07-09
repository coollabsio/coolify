<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class DashboardHealthcheckAlertService
{
    public function downWithoutHealthcheckForTeam(): Collection
    {
        return collect()
            ->merge($this->applicationAlerts())
            ->merge($this->databaseAlerts())
            ->merge($this->serviceStackAlerts())
            ->sortBy('name')
            ->values();
    }

    private function applicationAlerts(): Collection
    {
        return Application::ownedByCurrentTeam()
            ->with('environment.project')
            ->where('health_check_enabled', false)
            ->where('custom_healthcheck_found', false)
            ->get(['id', 'uuid', 'name', 'status', 'environment_id', 'health_check_enabled', 'custom_healthcheck_found'])
            ->filter(fn (Application $application) => $application->isExited())
            ->map(fn (Application $application) => $this->buildApplicationAlert($application));
    }

    private function buildApplicationAlert(Application $application): array
    {
        $project = $application->environment?->project;
        $environment = $application->environment;

        return [
            'name' => $application->name,
            'type' => 'application',
            'type_label' => 'Application',
            'project_name' => $project?->name,
            'environment_name' => $environment?->name,
            'parent_name' => null,
            'status' => $application->getRawOriginal('status') ?? $application->status,
            'url' => ($project && $environment)
                ? route('project.application.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'application_uuid' => $application->uuid,
                ])
                : null,
            'healthcheck_url' => ($project && $environment)
                ? route('project.application.healthcheck', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'application_uuid' => $application->uuid,
                ])
                : null,
        ];
    }

    private function databaseAlerts(): Collection
    {
        $alerts = collect();

        foreach ($this->standaloneDatabaseModelClasses() as $modelClass) {
            $alerts = $alerts->merge(
                $modelClass::ownedByCurrentTeam()
                    ->with('environment.project')
                    ->where('health_check_enabled', false)
                    ->get(['id', 'uuid', 'name', 'status', 'environment_id', 'health_check_enabled'])
                    ->filter(fn ($database) => $database->isExited())
                    ->map(fn ($database) => $this->buildDatabaseAlert($database))
            );
        }

        return $alerts;
    }

    private function buildDatabaseAlert(object $database): array
    {
        $project = $database->environment?->project;
        $environment = $database->environment;

        return [
            'name' => $database->name,
            'type' => 'database',
            'type_label' => $this->databaseTypeLabel($database),
            'project_name' => $project?->name,
            'environment_name' => $environment?->name,
            'parent_name' => null,
            'status' => $database->getRawOriginal('status') ?? $database->status,
            'url' => ($project && $environment)
                ? route('project.database.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'database_uuid' => $database->uuid,
                ])
                : null,
            'healthcheck_url' => ($project && $environment)
                ? route('project.database.healthcheck', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'database_uuid' => $database->uuid,
                ])
                : null,
        ];
    }

    private function serviceStackAlerts(): Collection
    {
        $alerts = collect();

        $services = Service::ownedByCurrentTeam()
            ->with([
                'environment.project',
                'applications:id,uuid,name,status,service_id',
                'databases:id,uuid,name,status,service_id',
            ])
            ->get(['id', 'uuid', 'name', 'environment_id', 'docker_compose_raw']);

        foreach ($services as $service) {
            $composeCache = $this->parseDockerCompose($service->docker_compose_raw);

            foreach ($service->applications as $serviceApplication) {
                if (! $serviceApplication->isExited()) {
                    continue;
                }

                if ($this->containerHasHealthcheck($composeCache, $serviceApplication->name)) {
                    continue;
                }

                $alerts->push($this->buildServiceContainerAlert($service, $serviceApplication));
            }

            foreach ($service->databases as $serviceDatabase) {
                if (! $serviceDatabase->isExited()) {
                    continue;
                }

                if ($this->containerHasHealthcheck($composeCache, $serviceDatabase->name)) {
                    continue;
                }

                $alerts->push($this->buildServiceContainerAlert($service, $serviceDatabase));
            }
        }

        return $alerts;
    }

    private function buildServiceContainerAlert(Service $service, ServiceApplication|ServiceDatabase $container): array
    {
        $project = $service->environment?->project;
        $environment = $service->environment;

        return [
            'name' => $container->human_name ?: $container->name,
            'type' => 'service',
            'type_label' => 'Service container',
            'project_name' => $project?->name,
            'environment_name' => $environment?->name,
            'parent_name' => $service->name,
            'status' => $container->getRawOriginal('status') ?? $container->status,
            'url' => ($project && $environment)
                ? route('project.service.index', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'service_uuid' => $service->uuid,
                    'stack_service_uuid' => $container->uuid,
                ])
                : null,
            'healthcheck_url' => ($project && $environment)
                ? route('project.service.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'service_uuid' => $service->uuid,
                ])
                : null,
        ];
    }

    private function parseDockerCompose(?string $dockerComposeRaw): array
    {
        if (! $dockerComposeRaw) {
            return [];
        }

        try {
            $dockerCompose = Yaml::parse($dockerComposeRaw);

            if (! is_array($dockerCompose)) {
                return [];
            }

            $services = data_get($dockerCompose, 'services', []);

            return is_array($services) ? $services : [];
        } catch (ParseException $e) {
            Log::warning('Failed to parse Docker Compose YAML for dashboard healthcheck alerts', [
                'error' => $e->getMessage(),
                'line' => $e->getParsedLine(),
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('Unexpected error parsing Docker Compose YAML for dashboard healthcheck alerts', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function containerHasHealthcheck(array $composeServices, string $containerName): bool
    {
        $serviceConfig = $composeServices[$containerName] ?? null;

        if (! is_array($serviceConfig)) {
            return false;
        }

        if (data_get($serviceConfig, 'exclude_from_hc', false)) {
            return false;
        }

        if (data_get($serviceConfig, 'restart') === 'no') {
            return false;
        }

        return isset($serviceConfig['healthcheck']);
    }

    private function databaseTypeLabel(object $database): string
    {
        $type = method_exists($database, 'type') ? $database->type() : class_basename($database);

        return str($type)
            ->replace('standalone-', '')
            ->replace('-', ' ')
            ->title()
            ->toString();
    }

    private function standaloneDatabaseModelClasses(): array
    {
        return [
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneRedis::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];
    }
}
