<?php

namespace App\Livewire\ImageScan;

use App\Services\ImageScan\TeamVulnerabilities;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The team Vulnerabilities page (spec 6.5): the resources of the session team the user can see, worst first, and a
 * search by vulnerability ID or package across their current scans.
 */
class FleetVulnerabilities extends Component
{
    use ListensToTeamChannel;
    use WithPagination;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    #[Url(except: '')]
    public string $search = '';

    /** @var list<string> Server UUIDs. */
    public array $servers = [];

    /** @var list<string> Project UUIDs. */
    public array $projects = [];

    /** @var list<string> */
    public array $severities = [];

    public int $perPage = 10;

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
        if (in_array($property, ['search', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function toggleServer(string $uuid): void
    {
        $this->servers = $this->toggle($this->servers, $uuid);
    }

    public function toggleProject(string $uuid): void
    {
        $this->projects = $this->toggle($this->projects, $uuid);
    }

    public function toggleSeverity(string $severity): void
    {
        if (in_array($severity, self::SEVERITIES, true)) {
            $this->severities = $this->toggle($this->severities, $severity);
        }
    }

    public function resetFilters(): void
    {
        $this->servers = [];
        $this->projects = [];
        $this->severities = [];
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
        $this->severities = array_values(array_intersect($this->severities, self::SEVERITIES));

        $filtered = $entries->filter(fn (array $entry): bool => ($this->servers === [] || in_array($entry['server']?->uuid, $this->servers, true))
            && ($this->projects === [] || in_array($entry['project']?->uuid, $this->projects, true)))->values();

        $notScanned = $filtered->reject(fn (array $entry): bool => $entry['scanned']);
        $scanned = $filtered->filter(fn (array $entry): bool => $entry['scanned'])->values();
        $searching = trim($this->search) !== '';

        $rows = $searching
            ? $fleet->search($scanned, $this->search, $this->severities)
            : $this->worstFirst($scanned);
        $items = $this->paginate($rows);
        $packageCounts = $searching ? [] : $fleet->fixablePackageCounts(collect($items->items())
            ->filter(fn (array $entry): bool => $entry['summary']['count'] > 0 && $entry['summary']['update'] === null));
        $notScannedServers = $notScanned->pluck('server')->filter()->unique('id')->count();

        return view('livewire.image-scan.fleet-vulnerabilities', [
            'allowed' => true,
            'items' => $items,
            'searching' => $searching,
            'packageCounts' => $packageCounts,
            'notScannedLine' => $notScanned->isEmpty() ? null : self::notScannedSentence($notScanned->count(), $notScannedServers),
            'searchCapped' => $searching && $fleet->searchWasCapped(),
            'serversNotScanning' => $user->isAdminOfTeam($team->id) ? TeamVulnerabilities::serversNotScanning($team->id) : 0,
            'serverOptions' => $serverOptions,
            'projectOptions' => $projectOptions,
            'severityOptions' => self::SEVERITIES,
            'hasFilters' => $this->servers !== [] || $this->projects !== [] || $this->severities !== [],
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
     * The team's resources and their scan totals, loaded once per request.
     */
    private function fleet(int $teamId): TeamVulnerabilities
    {
        if ($this->fleet?->teamId !== $teamId) {
            $this->fleet = TeamVulnerabilities::load($teamId);
        }

        return $this->fleet;
    }

    /**
     * Known exploited first, then the count at the alert level, then critical and high counts.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function worstFirst(Collection $entries): Collection
    {
        if ($this->severities !== []) {
            $entries = $entries->filter(fn (array $entry): bool => collect($this->severities)
                ->contains(fn (string $severity): bool => ($entry['summary']['fixable'][$severity] ?? 0) > 0));
        }

        $rank = fn (array $entry): array => [
            $entry['summary']['kev'],
            $entry['summary']['count'],
            $entry['summary']['fixable']['critical'],
            $entry['summary']['fixable']['high'],
        ];

        return $entries
            ->sort(fn (array $a, array $b): int => ($rank($b) <=> $rank($a)) ?: strnatcasecmp((string) $a['resource']->name, (string) $b['resource']->name))
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
