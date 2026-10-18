<?php

namespace App\Livewire;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\TeamInvitation;
use Illuminate\Support\Collection;
use Livewire\Component;

class Dashboard extends Component
{
    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    public function mount()
    {
        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->projects = Project::ownedByCurrentTeam()
            ->with(['environments:id,uuid,name,project_id'])
            ->withCount([
                'applications',
                'services',
                'postgresqls',
                'redis',
                'keydbs',
                'dragonflies',
                'clickhouses',
                'mongodbs',
                'mysqls',
                'mariadbs',
                'sqlites',
            ])
            ->get();
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'pendingInvitations' => $this->pendingInvitations(),
        ]);
    }

    /**
     * Unexpired invitations for the user's email to teams the user has not joined yet.
     *
     * Uses hasExpired() instead of isValid() so rendering never deletes invitations or users.
     *
     * @return Collection<int, TeamInvitation>
     */
    private function pendingInvitations(): Collection
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        return TeamInvitation::query()
            ->where('email', strtolower($user->email))
            ->whereHas('team')
            ->whereNotIn('team_id', $user->teams()->select('teams.id'))
            ->with('team:id,name')
            ->oldest()
            ->get()
            ->reject(fn (TeamInvitation $invitation) => $invitation->hasExpired())
            ->values();
    }
}
