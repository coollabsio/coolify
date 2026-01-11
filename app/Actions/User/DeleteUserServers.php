<?php

namespace App\Actions\User;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Collection;

class DeleteUserServers
{
    private User $user;

    private bool $isDryRun;

    public function __construct(User $user, bool $isDryRun = false)
    {
        $this->user = $user;
        $this->isDryRun = $isDryRun;
    }

    public function getServersPreview(): Collection
    {
        $servers = collect();

        // Get all teams the user belongs to
        $teams = $this->user->teams()->get();

        foreach ($teams as $team) {
            // Only include servers from teams where user is owner or admin
            $userRole = $team->pivot->role;
            if ($userRole === 'owner' || $userRole === 'admin') {
                $teamServers = $team->servers()->get();
                $servers = $servers->merge($teamServers);
            }
        }

        // Return unique servers (in case same server is in multiple teams)
        return $servers->unique('id');
    }

    public function execute(): array
    {
        if ($this->isDryRun) {
            return [
                'servers' => 0,
            ];
        }

        $deletedCount = 0;

        $servers = $this->getServersPreview();

        foreach ($servers as $server) {
            try {
                // Skip the default server (ID 0) which is the Coolify host
                if ($server->id === 0) {
                    \Log::info('Skipping deletion of Coolify host server (ID: 0)');

                    continue;
                }

                // The Server model's forceDeleting event will handle cleanup of:
                // - destinations
                // - settings
                $server->forceDelete();
                $deletedCount++;
            } catch (\Exception $e) {
                \Log::error("Failed to delete server {$server->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        return [
            'servers' => $deletedCount,
        ];
    }
}
