<?php

namespace App\Livewire\ImageScan;

use App\Enums\SecurityStatusType;
use App\Services\ImageScan\FleetExport;
use App\Services\ImageScan\TeamVulnerabilities;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The team Security page (spec 15.8): the resources of the session team the user can see with their stored security
 * status, most urgent first, and a search by vulnerability ID or package across their current scans. No buttons and
 * no bulk actions: the Action column links to the Security card of each resource. Resources that were not scanned are
 * hidden until the user shows them (the line under the table counts them) or filters by "Not checked".
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

    /** Shows the resources that were not scanned when no status filter is set. */
    #[Url(except: false)]
    public bool $showNotChecked = false;

    public int $perPage = 25;

    /** At most one reload per this many seconds for scan events of other resources. */
    public const RELOAD_INTERVAL = 10;

    /** When a scan event last reloaded the page (Unix time). */
    public int $reloadedAt = 0;

    /** A scan result came in during the interval; the page reloads once the interval ends. */
    public bool $reloadPending = false;

    private ?TeamVulnerabilities $fleet = null;

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ImageScanUpdated' => 'scanUpdated',
        ]);
    }

    /**
     * A scan finished somewhere in the team: render again with the new results, at most once per RELOAD_INTERVAL
     * seconds, because a scan of a whole server sends one event per image. Progress updates of a running scan change no
     * result, so they do not reload the page.
     *
     * @param  array<string, mixed>  $event
     */
    public function scanUpdated(array $event = []): void
    {
        if (data_get($event, 'final', true) === false) {
            $this->skipRender();

            return;
        }

        $now = now()->getTimestamp();
        if ($now - $this->reloadedAt < self::RELOAD_INTERVAL) {
            $this->reloadPending = true;
            $this->skipRender();

            return;
        }

        $this->reloadedAt = $now;
        $this->reloadPending = false;
    }

    /**
     * Render the scan results that came in during the last interval.
     */
    public function reloadPendingResults(): void
    {
        if (! $this->reloadPending) {
            $this->skipRender();

            return;
        }

        $this->reloadedAt = now()->getTimestamp();
        $this->reloadPending = false;
    }

    public function toggleNotChecked(): void
    {
        $this->showNotChecked = ! $this->showNotChecked;
        $this->resetPage();
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

    /**
     * The resources in scope (server and project filters), the ones the table shows (status filter, or the search
     * matches) on all its pages, and the filter options.
     *
     * @return array{fleet: TeamVulnerabilities, inScope: Collection<int, array<string, mixed>>, notScanned: Collection<int, array<string, mixed>>, rows: Collection<int, array<string, mixed>>, searching: bool, serverOptions: array<string, string>, projectOptions: array<string, string>}
     */
    private function listing(): array
    {
        $this->normalizeFilters();
        $fleet = $this->fleet(currentTeam()->id);
        $entries = $fleet->entries;

        $serverOptions = $entries->pluck('server')->filter()->unique('id')
            ->mapWithKeys(fn ($server) => [$server->uuid => $server->name])->sort(SORT_NATURAL | SORT_FLAG_CASE)->all();
        $projectOptions = $entries->pluck('project')->filter()->unique('id')
            ->mapWithKeys(fn ($project) => [$project->uuid => $project->name])->sort(SORT_NATURAL | SORT_FLAG_CASE)->all();
        $this->servers = array_values(array_intersect($this->servers, array_keys($serverOptions)));
        $this->projects = array_values(array_intersect($this->projects, array_keys($projectOptions)));

        $inScope = $entries->filter(fn (array $entry): bool => ($this->servers === [] || in_array($entry['server']?->uuid, $this->servers, true))
            && ($this->projects === [] || in_array($entry['project']?->uuid, $this->projects, true)))->values();
        $filtered = $inScope->filter(fn (array $entry): bool => $this->statuses === []
            ? $this->showNotChecked || $entry['security']->status !== SecurityStatusType::NotChecked
            : in_array($entry['security']->status->value, $this->statuses, true))->values();

        $searching = trim($this->search) !== '';
        $rows = $searching
            ? $fleet->search($filtered->filter(fn (array $entry): bool => $entry['scanned'])->values(), $this->search)
            : self::byStatus($filtered);

        return [
            'fleet' => $fleet,
            'inScope' => $inScope,
            'notScanned' => $inScope->filter(fn (array $entry): bool => $entry['security']->status === SecurityStatusType::NotChecked),
            'rows' => $rows,
            'searching' => $searching,
            'serverOptions' => $serverOptions,
            'projectOptions' => $projectOptions,
        ];
    }

    /**
     * The shown resources as a Markdown table for the clipboard.
     */
    public function copyList(string $format): string
    {
        abort_unless($format === 'markdown', 404);
        $this->skipRender();
        ['rows' => $rows, 'searching' => $searching] = $this->listing();
        $count = number_format($rows->count()).' '.str('resource')->plural($rows->count());
        $title = $searching
            ? 'Resources with '.trim($this->search)." ({$count})"
            : 'Security of '.currentTeam()->name." ({$count})";

        return FleetExport::markdown($title, FleetExport::entries($rows, $searching, TeamVulnerabilities::STATE_LABELS), $searching);
    }

    /**
     * Downloads the shown resources as CSV or JSON.
     */
    public function downloadList(string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'json'], true), 404);
        ['rows' => $rows, 'searching' => $searching] = $this->listing();
        $entries = FleetExport::entries($rows, $searching, TeamVulnerabilities::STATE_LABELS);
        $team = currentTeam();
        $content = $format === 'csv'
            ? FleetExport::csv($entries, $searching)
            : FleetExport::json(['team' => (string) $team->name, 'search' => $searching ? trim($this->search) : null], $entries);
        $name = (Str::slug((string) $team->name) ?: 'team').'-security-'.now()->toDateString().'.'.$format;

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $name, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json']);
    }

    public function render(): View
    {
        [
            'fleet' => $fleet,
            'inScope' => $inScope,
            'notScanned' => $notScanned,
            'rows' => $rows,
            'searching' => $searching,
            'serverOptions' => $serverOptions,
            'projectOptions' => $projectOptions,
        ] = $this->listing();
        $user = auth()->user();
        $team = currentTeam();
        $entries = $fleet->entries;

        $statusCounts = $inScope->countBy(fn (array $entry): string => $entry['security']->status->value);
        $statusOptions = collect(TeamVulnerabilities::STATUS_ORDER)
            ->mapWithKeys(fn (string $status): array => [$status => TeamVulnerabilities::statusLabel(SecurityStatusType::from($status)).' ('.number_format($statusCounts[$status] ?? 0).')'])
            ->all();

        $notScannedServers = $notScanned->pluck('server')->filter()->unique('id')->count();

        return view('livewire.image-scan.fleet-vulnerabilities', [
            'items' => $this->paginate($rows),
            'shownCount' => $rows->count(),
            'searching' => $searching,
            'notScannedLine' => $notScanned->isEmpty() ? null : self::notScannedSentence($notScanned->count(), $notScannedServers),
            // The toggle only matters while no status filter is set.
            'notCheckedToggle' => $notScanned->isEmpty() || $this->statuses !== [] || $searching ? null : ($this->showNotChecked ? 'Hide them' : 'Show them'),
            'hidesNotChecked' => $this->statuses === [] && ! $this->showNotChecked && $notScanned->isNotEmpty(),
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
