<?php

namespace App\Livewire;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\TeamInvitation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Dashboard extends Component
{
    use RemembersCanvasView;

    /** The dashboard shows only the first projects and servers of the sorted lists. */
    public const ITEM_LIMIT = 8;

    private const PROJECT_SORT_COLUMNS = ['name', 'resources', 'description'];

    private const SERVER_SORT_COLUMNS = ['name', 'description', 'status'];

    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    #[Locked]
    public string $projectSort = 'name-asc';

    #[Locked]
    public string $serverSort = 'name-asc';

    public function mount()
    {
        if ($this->prefersCanvasView('dashboard')) {
            $this->redirectRoute('dashboard.canvas', navigate: true);

            return;
        }

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
        $dashboardProjects = $this->sortedProjects()->take(self::ITEM_LIMIT);
        $dashboardServers = $this->sortedServers()->take(self::ITEM_LIMIT);

        return view('livewire.dashboard', [
            'pendingInvitations' => $this->pendingInvitations(),
            'dashboardProjects' => $dashboardProjects,
            'dashboardServers' => $dashboardServers,
            'projectResourceCounts' => $dashboardProjects->mapWithKeys(fn (Project $project) => [$project->id => $this->projectResourceCount($project)]),
            'serverStatuses' => $dashboardServers->mapWithKeys(fn (Server $server) => [$server->id => $this->serverStatus($server)]),
        ]);
    }

    public function setProjectSort(string $sort): void
    {
        if ($this->isValidSort($sort, self::PROJECT_SORT_COLUMNS)) {
            $this->projectSort = $sort;
        }
    }

    public function setServerSort(string $sort): void
    {
        if ($this->isValidSort($sort, self::SERVER_SORT_COLUMNS)) {
            $this->serverSort = $sort;
        }
    }

    private function projectResourceCount(Project $project): int
    {
        return $project->applications_count + $project->services_count + $project->postgresqls_count
            + $project->redis_count + $project->keydbs_count + $project->dragonflies_count
            + $project->clickhouses_count + $project->mongodbs_count + $project->mysqls_count
            + $project->mariadbs_count + $project->sqlites_count;
    }

    /**
     * @return array{0: string, 1: 'success'|'warning'|'error'} label and badge type
     */
    private function serverStatus(Server $server): array
    {
        $proxyNeedsAttention = $server->proxySet() && ($server->proxy->status !== 'running' || $server->hasCurrentTraefikOutdatedInfo());
        $sentinelNeedsAttention = $server->isSentinelEnabled() && $server->sentinelStatus() === 'out_of_sync';

        return match (true) {
            $server->settings->force_disabled => ['Disabled', 'error'],
            ! $server->settings->is_reachable && ! $server->settings->is_usable => ['Unavailable', 'error'],
            ! $server->settings->is_reachable => ['Unreachable', 'error'],
            ! $server->settings->is_usable => ['Not ready', 'warning'],
            $proxyNeedsAttention || $sentinelNeedsAttention => ['Attention required', 'warning'],
            default => ['Ready', 'success'],
        };
    }

    /**
     * @return Collection<int, Project>
     */
    private function sortedProjects(): Collection
    {
        return $this->sortItems($this->projects, $this->projectSort, fn (Project $project, string $column): int|string => match ($column) {
            'resources' => $this->projectResourceCount($project),
            'description' => (string) $project->description,
            default => $project->name,
        });
    }

    /**
     * @return Collection<int, Server>
     */
    private function sortedServers(): Collection
    {
        return $this->sortItems($this->servers, $this->serverSort, fn (Server $server, string $column): string => match ($column) {
            'description' => (string) $server->description,
            'status' => $this->serverStatus($server)[0],
            default => $server->name,
        });
    }

    /**
     * Sorts by the column of a `column-direction` sort value, with the name as tie-breaker.
     *
     * @param  callable(mixed, string): (int|string)  $value
     */
    private function sortItems(Collection $items, string $sort, callable $value): Collection
    {
        [$column, $direction] = explode('-', $sort, 2);

        return $items
            ->sortBy(fn ($item) => $item->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->sortBy(fn ($item) => $value($item, $column), SORT_NATURAL | SORT_FLAG_CASE, $direction === 'desc')
            ->values();
    }

    /**
     * @param  list<string>  $columns
     */
    private function isValidSort(string $sort, array $columns): bool
    {
        [$column, $direction] = array_pad(explode('-', $sort, 2), 2, null);

        return in_array($column, $columns, true) && in_array($direction, ['asc', 'desc'], true);
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
