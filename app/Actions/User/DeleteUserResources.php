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
        $teams = $this->user->teams()->get();

        foreach ($teams as $team) {
            // Only delete resources from teams that will be FULLY DELETED
            // This means: user is the ONLY member of the team
            //
            // DO NOT delete resources if:
            // - User is just a member (not owner)
            // - Team has other members (ownership will be transferred or user just removed)

            $userRole = $team->pivot->role;
            $memberCount = $team->members->count();

            // Skip if user is not owner
            if ($userRole !== 'owner') {
                continue;
            }

            // Skip if team has other members (will be transferred/user removed, not deleted)
            if ($memberCount > 1) {
                continue;
            }

            // Only delete resources from teams where user is the ONLY member
            // These teams will be fully deleted

            // Get all servers for this team
            $servers = $team->servers()->get();

            foreach ($servers as $server) {
                // Get applications (custom method returns Collection)
                $serverApplications = $server->applications();
                $applications = $applications->merge($serverApplications);

                // Get databases (custom method returns Collection)
                $serverDatabases = $server->databases();
                $databases = $databases->merge($serverDatabases);

                // Get services (relationship needs ->get())
                $serverServices = $server->services()->get();
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
}
