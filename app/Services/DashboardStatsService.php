<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Support\Collection;

class DashboardStatsService
{
    /**
     * @return array{
     *     servers: array{total: int, active: int, inactive: int},
     *     projects: array{total: int, active: int, inactive: int},
     *     applications: array{total: int, active: int, inactive: int},
     *     services: array{total: int, active: int, inactive: int},
     *     databases: array{total: int, active: int, inactive: int},
     * }
     */
    public function forTeam(?Collection $servers = null): array
    {
        $servers ??= Server::ownedByCurrentTeamCached()->load('settings');

        return [
            'servers' => $this->serverStats($servers),
            'projects' => $this->projectStats(),
            'applications' => $this->applicationStats(),
            'services' => $this->serviceStats(),
            'databases' => $this->databaseStats(),
        ];
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    private function serverStats(Collection $servers): array
    {
        $active = $servers->filter(function (Server $server) {
            return $server->settings?->is_reachable && ! $server->settings?->force_disabled;
        })->count();

        return $this->buildStat($servers->count(), $active);
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    private function projectStats(): array
    {
        $projects = Project::ownedByCurrentTeam()
            ->withCount([
                'applications',
                'services',
                'redis',
                'postgresqls',
                'mysqls',
                'keydbs',
                'dragonflies',
                'clickhouses',
                'mariadbs',
                'mongodbs',
            ])
            ->get();

        $active = $projects->filter(function (Project $project) {
            return $project->applications_count > 0
                || $project->services_count > 0
                || $project->redis_count > 0
                || $project->postgresqls_count > 0
                || $project->mysqls_count > 0
                || $project->keydbs_count > 0
                || $project->dragonflies_count > 0
                || $project->clickhouses_count > 0
                || $project->mariadbs_count > 0
                || $project->mongodbs_count > 0;
        })->count();

        return $this->buildStat($projects->count(), $active);
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    private function applicationStats(): array
    {
        $applications = Application::ownedByCurrentTeam()->select('id', 'status')->get();
        $active = $applications->filter(fn (Application $application) => $application->isRunning())->count();

        return $this->buildStat($applications->count(), $active);
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    private function serviceStats(): array
    {
        $services = Service::ownedByCurrentTeam()
            ->with(['applications:id,service_id,status', 'databases:id,service_id,status'])
            ->get(['id', 'uuid', 'name']);

        $active = $services->filter(fn (Service $service) => $service->isRunning())->count();

        return $this->buildStat($services->count(), $active);
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    private function databaseStats(): array
    {
        $databases = collect();

        foreach ($this->standaloneDatabaseModelClasses() as $modelClass) {
            $databases = $databases->merge(
                $modelClass::ownedByCurrentTeam()->select('id', 'status')->get()
            );
        }

        $active = $databases->filter(fn ($database) => $database->isRunning())->count();

        return $this->buildStat($databases->count(), $active);
    }

    /**
     * @return array<int, class-string>
     */
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

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    private function buildStat(int $total, int $active): array
    {
        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
        ];
    }
}
