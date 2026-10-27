<?php

namespace App\Livewire\ImageScan;

use App\Enums\SecurityStatusType;
use App\Services\ImageScan\TeamVulnerabilities;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The team Security page (spec 15.8): the resources of the session team the user can see with their stored security
 * status, most urgent first, and a search by vulnerability ID or package across their current scans. No buttons and
 * no bulk actions: the Action column links to the Security card of each resource.
 */
class FleetVulnerabilities extends Component
{
    use ListensToTeamChannel;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /**
     * Untyped, like $projects and $statuses: a malformed URL or request value (a string, nested arrays) must not fail
     * the page. `normalizeFilters()` keeps only known string values.
     *
     * @var list<string> Server UUIDs.
     */
    public $servers = [];

    /** @var list<string> Project UUIDs. */
    public $projects = [];

    /** @var list<string> SecurityStatusType values. */
    #[Url(except: [])]
    public $statuses = [];

    public int $perPage = 25;

    private ?TeamVulnerabilities $fleet = null;

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ImageScanUpdated' => 'scanUpdated',
        ]);
    }

    /**
     * A scan finished somewhere in the team: render again with the new results. Progress updates of a running scan
     * change no result, so they do not reload the page.
     *
     * @param  array<string, mixed>  $event
     */
    public function scanUpdated(array $event = []): void
    {
        if (data_get($event, 'final', true) === false) {
            $this->skipRender();
        }
    }

    public function updated(string $property): void
    {
        $this->normalizeFilters();

        if (in_array($property, ['search', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Keeps only string values, and only allowed statuses. Servers and projects are checked against the options in
     * render().
     */
    private function normalizeFilters(): void
    {
        $strings = fn (mixed $values): array => array_values(array_unique(array_filter(is_array($values) ? $values : [], 'is_string')));

        $this->servers = $strings($this->servers);
        $this->projects = $strings($this->projects);
        $this->statuses = array_values(array_intersect($strings($this->statuses), TeamVulnerabilities::STATUS_ORDER));
    }

    public function toggleServer(string $uuid): void
    {
        $this->servers = $this->toggle($this->servers, $uuid);
    }

    public function toggleProject(string $uuid): void
    {
        $this->projects = $this->toggle($this->projects, $uuid);
    }

    public function toggleStatus(string $status): void
    {
        if (in_array($status, TeamVulnerabilities::STATUS_ORDER, true)) {
            $this->statuses = $this->toggle($this->statuses, $status);
        }
    }

    public function resetFilters(): void
    {
        $this->servers = [];
        $this->projects = [];
        $this->statuses = [];
        $this->resetPage();
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function toggle(array $values, string $value): array
    {
        $this->resetPage();

        return in_array($value, $values, true)
            ? array_values(array_diff($values, [$value]))
            : [...$values, $value];
    }

    public function render(): View
    {
        $this->normalizeFilters();
        $user = auth()->user();
        $team = currentTeam();
        $allowed = (bool) instanceSettings()->is_image_scanning_allowed;

        if (! $allowed) {
            return view('livewire.image-scan.fleet-vulnerabilities', ['allowed' => false]);
        }

        $fleet = $this->fleet($team->id);
        $entries = $fleet->entries;

        $serverOptions = $entries->pluck('server')->filter()->unique('id')
            ->mapWithKeys(fn ($server) => [$server->uuid => $server->name])->sort(SORT_NATURAL | SORT_FLAG_CASE)->all();
        $projectOptions = $entries->pluck('project')->filter()->unique('id')
            ->mapWithKeys(fn ($project) => [$project->uuid => $project->name])->sort(SORT_NATURAL | SORT_FLAG_CASE)->all();
        $this->servers = array_values(array_intersect($this->servers, array_keys($serverOptions)));
        $this->projects = array_values(array_intersect($this->projects, array_keys($projectOptions)));

        $inScope = $entries->filter(fn (array $entry): bool => ($this->servers === [] || in_array($entry['server']?->uuid, $this->servers, true))
            && ($this->projects === [] || in_array($entry['project']?->uuid, $this->projects, true)))->values();
        $statusCounts = $inScope->countBy(fn (array $entry): string => $entry['security']->status->value);
        $statusOptions = collect(TeamVulnerabilities::STATUS_ORDER)
            ->mapWithKeys(fn (string $status): array => [$status => TeamVulnerabilities::statusLabel(SecurityStatusType::from($status)).' ('.($statusCounts[$status] ?? 0).')'])
            ->all();
        $filtered = $inScope->filter(fn (array $entry): bool => $this->statuses === [] || in_array($entry['security']->status->value, $this->statuses, true))->values();

        $notScanned = $inScope->filter(fn (array $entry): bool => $entry['security']->status === SecurityStatusType::NotChecked);
        $searching = trim($this->search) !== '';

        $rows = $searching
            ? $fleet->search($filtered->filter(fn (array $entry): bool => $entry['scanned'])->values(), $this->search)
            : self::byStatus($filtered);
        $notScannedServers = $notScanned->pluck('server')->filter()->unique('id')->count();

        return view('livewire.image-scan.fleet-vulnerabilities', [
            'allowed' => true,
            'items' => $this->paginate($rows),
            'searching' => $searching,
            'notScannedLine' => $notScanned->isEmpty() ? null : self::notScannedSentence($notScanned->count(), $notScannedServers),
            'searchCapped' => $searching && $fleet->searchWasCapped(),
            'serversNotScanning' => $user->isAdminOfTeam($team->id) ? TeamVulnerabilities::serversNotScanning($team->id) : 0,
            'serverOptions' => $serverOptions,
            'projectOptions' => $projectOptions,
            'statusOptions' => $statusOptions,
            'hasFilters' => $this->servers !== [] || $this->projects !== [] || $this->statuses !== [],
            'hasResources' => $entries->isNotEmpty(),
            'stateLabels' => TeamVulnerabilities::STATE_LABELS,
        ]);
    }

    /**
     * "3 resources on 2 servers were not scanned, so Coolify cannot tell." Resources without a server leave out the
     * servers part when no server is left.
     */
    public static function notScannedSentence(int $resources, int $servers): string
    {
        $subject = $resources.' '.str('resource')->plural($resources);
        if ($servers > 0) {
            $subject .= ' on '.$servers.' '.str('server')->plural($servers);
        }

        return $subject.($resources === 1 ? ' was' : ' were').' not scanned, so Coolify cannot tell.';
    }

    /**
     * The team's resources and their stored statuses, loaded once per request.
     */
    private function fleet(int $teamId): TeamVulnerabilities
    {
        if ($this->fleet?->teamId !== $teamId) {
            $this->fleet = TeamVulnerabilities::load($teamId);
        }

        return $this->fleet;
    }

    /**
     * update_now, update_available, no_fix, check_needed, ok, not_checked; then the resource name.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    public static function byStatus(Collection $entries): Collection
    {
        $rank = array_flip(TeamVulnerabilities::STATUS_ORDER);

        return $entries
            ->sort(fn (array $a, array $b): int => ($rank[$a['security']->status->value] <=> $rank[$b['security']->status->value])
                ?: strnatcasecmp((string) $a['resource']->name, (string) $b['resource']->name))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function paginate(Collection $rows): LengthAwarePaginator
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $lastPage = max(1, (int) ceil($rows->count() / $this->perPage));
        $page = max(1, min((int) $this->getPage(), $lastPage));
        if ($page !== (int) $this->getPage()) {
            $this->setPage($page);
        }

        return new LengthAwarePaginator($rows->forPage($page, $this->perPage)->values(), $rows->count(), $this->perPage, $page);
    }
}
