<?php

namespace App\Livewire;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Star;
use App\Models\TeamInvitation;
use App\Services\Access\AccessResolver;
use App\Services\ImageScan\TeamVulnerabilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

    /**
     * Members limited to some projects do not see team-level sections such as servers.
     */
    #[Locked]
    public bool $showsTeamSections = true;

    /**
     * UUIDs of the resources the user can see that need a security update now (update_now).
     *
     * @var list<string>
     */
    #[Locked]
    public array $securityUpdateNow = [];

    public function mount()
    {
        $this->showsTeamSections = app(AccessResolver::class)->coversWholeTeam(auth()->user(), currentTeam()->id);

        if ($this->showsTeamSections && $this->prefersCanvasView('dashboard')) {
            $this->redirectRoute('dashboard.canvas', navigate: true);

            return;
        }

        $this->securityUpdateNow = TeamVulnerabilities::updateNowResources(currentTeam()->id);
        $this->privateKeys = $this->showsTeamSections ? PrivateKey::ownedByCurrentTeamCached() : collect();
        $this->servers = $this->showsTeamSections ? Server::ownedByCurrentTeamCached() : collect();
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
            'starredResources' => $this->starredResources(),
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

    public function unstar(int $starId): void
    {
        auth()->user()->stars()->whereKey($starId)->delete();
    }

    /**
     * Saves the order of the starred cards after a drag and drop. Stars of other teams keep their positions.
     *
     * @param  list<int>  $starIds
     */
    public function reorderStars(array $starIds): void
    {
        $stars = auth()->user()->stars()->whereKey($starIds)->get()->keyBy('id');

        foreach (array_values($starIds) as $position => $starId) {
            $stars->get((int) $starId)?->update(['position' => $position]);
        }
    }

    /**
     * Resources the user starred, in the current team, that the user can still see, in the order the user chose.
     *
     * @return Collection<int, array{star_id: int, resource: Model, name: string, context: string, href: string}>
     */
    private function starredResources(): Collection
    {
        $user = auth()->user();
        $teamId = currentTeam()->id;

        return $user->stars()
            ->whereIn('starrable_type', Star::STARRABLE_TYPES)
            ->with(['starrable' => fn (MorphTo $morphTo) => $morphTo->morphWith(
                collect(Star::STARRABLE_TYPES)->reject(Server::class)->mapWithKeys(fn (string $type) => [$type => ['environment.project']])->all()
            )])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(function (Star $star) use ($user, $teamId): bool {
                $resource = $star->starrable;

                if (! $resource) {
                    return false;
                }

                if ($resource instanceof Server) {
                    return $this->showsTeamSections && $resource->team_id === $teamId && $user->can('view', $resource);
                }

                return $resource->environment?->project?->team_id === $teamId && $user->can('view', $resource);
            })
            ->map(fn (Star $star) => [
                'star_id' => $star->id,
                'resource' => $star->starrable,
                'name' => $star->starrable->name,
                'context' => $star->starrable instanceof Server
                    ? 'Server'
                    : $star->starrable->environment->project->name.' / '.$star->starrable->environment->name,
                'href' => $star->starrable instanceof Server
                    ? route('server.show', ['server_uuid' => $star->starrable->uuid])
                    : $star->starrable->link(),
            ])
            ->values();
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
     * "1 resource needs a security update now." / "3 resources need a security update now."
     */
    public static function securityCalloutTitle(int $count): string
    {
        return $count === 1
            ? '1 resource needs a security update now.'
            : "{$count} resources need a security update now.";
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
