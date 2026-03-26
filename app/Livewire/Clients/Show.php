<?php

namespace App\Livewire\Clients;

use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class Show extends Component
{
    public Team $client;

    public Collection $assignedServers;

    public Collection $availableServers;

    public Collection $assignedProjects;

    public Collection $availableProjects;

    public int $sourceTeamId;

    public function mount(int $teamId): void
    {
        if (! auth()->user()?->isInstanceAdmin()) {
            abort(403);
        }

        $this->client = Team::query()
            ->where('id', $teamId)
            ->where('is_client', true)
            ->firstOrFail();

        $this->sourceTeamId = (int) currentTeam()->id;

        $this->refreshResources();
    }

    public function render()
    {
        return view('livewire.clients.show');
    }

    public function assignServer(int $serverId): void
    {
        if (! auth()->user()?->isInstanceAdmin()) {
            abort(403);
        }

        $server = Server::query()
            ->where('id', $serverId)
            ->where('team_id', $this->sourceTeamId)
            ->firstOrFail();

        $server->update(['team_id' => $this->client->id]);
        $this->refreshResources();
        $this->dispatch('success', 'Servidor asignado.');
    }

    public function removeServer(int $serverId): void
    {
        if (! auth()->user()?->isInstanceAdmin()) {
            abort(403);
        }

        $server = Server::query()
            ->where('id', $serverId)
            ->where('team_id', $this->client->id)
            ->firstOrFail();

        $server->update(['team_id' => $this->sourceTeamId]);
        $this->refreshResources();
        $this->dispatch('success', 'Servidor desasignado.');
    }

    public function assignProject(int $projectId): void
    {
        if (! auth()->user()?->isInstanceAdmin()) {
            abort(403);
        }

        $project = Project::query()
            ->where('id', $projectId)
            ->where('team_id', $this->sourceTeamId)
            ->firstOrFail();

        $project->update(['team_id' => $this->client->id]);
        $this->refreshResources();
        $this->dispatch('success', 'Proyecto asignado.');
    }

    public function removeProject(int $projectId): void
    {
        if (! auth()->user()?->isInstanceAdmin()) {
            abort(403);
        }

        $project = Project::query()
            ->where('id', $projectId)
            ->where('team_id', $this->client->id)
            ->firstOrFail();

        $project->update(['team_id' => $this->sourceTeamId]);
        $this->refreshResources();
        $this->dispatch('success', 'Proyecto desasignado.');
    }

    private function refreshResources(): void
    {
        $this->assignedServers = Server::query()
            ->where('team_id', $this->client->id)
            ->orderByRaw('LOWER(name)')
            ->get();

        $this->availableServers = Server::query()
            ->where('team_id', $this->sourceTeamId)
            ->orderByRaw('LOWER(name)')
            ->get();

        $this->assignedProjects = Project::query()
            ->where('team_id', $this->client->id)
            ->orderByRaw('LOWER(name)')
            ->get();

        $this->availableProjects = Project::query()
            ->where('team_id', $this->sourceTeamId)
            ->orderByRaw('LOWER(name)')
            ->get();
    }
}

