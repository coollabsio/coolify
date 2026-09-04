<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SharedBuildTeams extends Component
{
    #[Locked]
    public Server $server;

    /**
     * Team ID => build access enabled.
     *
     * @var array<int|string, bool>
     */
    public array $teamAccess = [];

    public function mount(Server $server): void
    {
        abort_unless(isInstanceAdmin(), 403);

        $server->refresh();
        $server->load('settings');

        abort_unless($server->isBuildServer(), 404);

        $this->server = $server;
        $this->loadTeamAccess();
    }

    #[Computed]
    public function availableTeams(): Collection
    {
        return Team::query()
            ->whereKeyNot($this->server->team_id)
            ->orderByRaw('LOWER(name)')
            ->get(['id', 'name', 'description']);
    }

    public function save(): void
    {
        abort_unless(isInstanceAdmin(), 403);

        $this->server->refresh();
        $this->server->load('settings');

        if (! $this->server->isBuildServer()) {
            $this->dispatch(
                'error',
                'Build server sharing is unavailable.',
                'Only dedicated build servers can be shared.'
            );

            return;
        }

        $allowedTeamIds = $this->availableTeams
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $selectedTeamIds = collect($this->teamAccess)
            ->filter(fn ($enabled) => filter_var($enabled, FILTER_VALIDATE_BOOL))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->intersect($allowedTeamIds)
            ->values();

        $syncData = $selectedTeamIds
            ->mapWithKeys(fn (int $teamId) => [
                $teamId => ['can_build' => true],
            ])
            ->all();

        $this->server->sharedTeams()->sync($syncData);

        auditLog('ui.server.shared_build_teams_updated', [
            'server_id' => $this->server->id,
            'server_uuid' => $this->server->uuid,
            'owner_team_id' => $this->server->team_id,
            'shared_team_ids' => $selectedTeamIds->all(),
        ]);

        $this->loadTeamAccess();
        $this->dispatch('success', 'Shared build-server access updated.');
    }

    private function loadTeamAccess(): void
    {
        $sharedTeamIds = $this->server
            ->sharedTeams()
            ->wherePivot('can_build', true)
            ->pluck('teams.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->teamAccess = $this->availableTeams
            ->mapWithKeys(fn (Team $team) => [
                $team->id => in_array($team->id, $sharedTeamIds, true),
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.server.shared-build-teams');
    }
}
