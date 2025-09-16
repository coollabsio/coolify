<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Collection;

class DeleteUserResources
{
    private User $user;

    private bool $isDryRun;

    public function __construct(User $user, bool $isDryRun = false)
    {
        $this->user = $user;
        $this->isDryRun = $isDryRun;
    }

    public function getResourcesPreview(): array
    {
        $applications = collect();
        $databases = collect();
        $services = collect();

        // Get all teams the user belongs to
        $teams = $this->user->teams;

        foreach ($teams as $team) {
            // Get all servers for this team
            $servers = $team->servers;

            foreach ($servers as $server) {
                // Get applications
                $serverApplications = $server->applications;
                $applications = $applications->merge($serverApplications);

                // Get databases
                $serverDatabases = $this->getAllDatabasesForServer($server);
                $databases = $databases->merge($serverDatabases);

                // Get services
                $serverServices = $server->services;
                $services = $services->merge($serverServices);
            }
        }

        return [
            'applications' => $applications->unique('id'),
            'databases' => $databases->unique('id'),
            'services' => $services->unique('id'),
        ];
    }

    public function execute(): array
    {
        if ($this->isDryRun) {
            return [
                'applications' => 0,
                'databases' => 0,
                'services' => 0,
            ];
        }

        $deletedCounts = [
            'applications' => 0,
            'databases' => 0,
            'services' => 0,
        ];

        $resources = $this->getResourcesPreview();

        // Delete applications
        foreach ($resources['applications'] as $application) {
            try {
                $application->forceDelete();
                $deletedCounts['applications']++;
            } catch (\Exception $e) {
                \Log::error("Failed to delete application {$application->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        // Delete databases
        foreach ($resources['databases'] as $database) {
            try {
                $database->forceDelete();
                $deletedCounts['databases']++;
            } catch (\Exception $e) {
                \Log::error("Failed to delete database {$database->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        // Delete services
        foreach ($resources['services'] as $service) {
            try {
                $service->forceDelete();
                $deletedCounts['services']++;
            } catch (\Exception $e) {
                \Log::error("Failed to delete service {$service->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        return $deletedCounts;
    }

    private function getAllDatabasesForServer($server): Collection
    {
        $databases = collect();

        // Get all standalone database types
        $databases = $databases->merge($server->postgresqls);
        $databases = $databases->merge($server->mysqls);
        $databases = $databases->merge($server->mariadbs);
        $databases = $databases->merge($server->mongodbs);
        $databases = $databases->merge($server->redis);
        $databases = $databases->merge($server->keydbs);
        $databases = $databases->merge($server->dragonflies);
        $databases = $databases->merge($server->clickhouses);

        return $databases;
    }
}
