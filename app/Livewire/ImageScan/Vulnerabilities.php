<?php

namespace App\Livewire\ImageScan;

use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanFixState;
use App\Jobs\ScanResourceImagesJob;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\Server;
use App\Models\Service;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\ImageScanGate;
use App\Services\ImageScan\ImageScanPolicyResolver;
use App\Services\ImageScan\ResourceScans;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The findings of the images a resource runs (spec 6.2). The resource is reloaded and authorized on every request.
 */
class Vulnerabilities extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use WithPagination;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    private const SEVERITY_RANK = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'unknown' => 0];

    private const VIEWS = ['package', 'cve'];

    private const EXPIRES = ['30', '90', 'never'];

    /** Results older than this get a warning. */
    private const OLD_RESULTS_DAYS = 2;

    #[Locked]
    public string $resourceType;

    #[Locked]
    public string $resourceUuid;

    public string $fixState = 'fixable_now';

    /** @var list<string> */
    public array $severities = [];

    /** @var list<string> Image refs, for pages with more than one image. */
    public array $images = [];

    public string $view = 'package';

    public string $search = '';

    public bool $showIgnored = false;

    public int $perPage = 10;

    private ?Model $loadedResource = null;

    /** @var Collection<int, Model>|null */
    private ?Collection $loadedOwners = null;

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ImageScanUpdated' => 'scanUpdated',
        ]);
    }

    public function mount(string $resourceType, string $resourceUuid): void
    {
        abort_unless(in_array($resourceType, ResourceScans::RESOURCE_TYPES, true), 404);
        $this->resourceType = $resourceType;
        $this->resourceUuid = $resourceUuid;
        $this->resource();
    }

    /**
     * @param  array<string, string>  $event
     */
    public function scanUpdated(array $event = []): void
    {
        $uuids = $this->owners()
            ->map(fn (Model $owner): string => (string) $owner->getAttribute('uuid'))
            ->push($this->resourceUuid);

        if (! $uuids->contains((string) data_get($event, 'resourceUuid'))) {
            $this->skipRender();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['fixState', 'search', 'view', 'perPage', 'showIgnored'], true)) {
            $this->resetPage();
        }
    }

    public function setFixState(string $state): void
    {
        $this->fixState = ImageScanFixState::tryFrom($state)?->value ?? ImageScanFixState::FixableNow->value;
        $this->showIgnored = false;
        $this->resetPage();
    }

    public function toggleSeverity(string $severity): void
    {
        if (! in_array($severity, self::SEVERITIES, true)) {
            return;
        }

        $this->severities = in_array($severity, $this->severities, true)
            ? array_values(array_diff($this->severities, [$severity]))
            : [...$this->severities, $severity];
        $this->resetPage();
    }

    public function toggleImage(string $image): void
    {
        $this->images = in_array($image, $this->images, true)
            ? array_values(array_diff($this->images, [$image]))
            : [...$this->images, $image];
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->severities = [];
        $this->images = [];
        $this->resetPage();
    }

    public function scanNow(): void
    {
        $resource = $this->resource();
        $this->authorize('update', $resource);
        $owners = $this->owners();

        if ($this->progress($owners) !== null) {
            $this->dispatch('error', 'A scan is already running.');

            return;
        }

        if (! ImageScanPolicyResolver::for($resource)->scanEnabled) {
            $this->dispatch('error', 'Image scanning is off for this resource.');

            return;
        }

        $imageRefs = [];
        if ($resource instanceof Application) {
            $imageRefs = $this->applicationImageRefs($resource);
            if ($imageRefs === []) {
                $this->dispatch('error', 'Deploy the app first.');

                return;
            }
        }

        $started = RateLimiter::attempt(
            "image-scan-now:{$resource->getMorphClass()}:{$resource->getKey()}",
            1,
            fn () => ScanResourceImagesJob::dispatch($resource, null, $imageRefs),
            60,
        );

        if (! $started) {
            $this->dispatch('error', 'A scan started less than a minute ago. Try again later.');

            return;
        }

        auditLog('ui.image_scan.scan_now', [
            'team_id' => $resource->team()?->id,
            'resource_type' => $resource->getMorphClass(),
            'resource_uuid' => $this->resourceUuid,
        ]);
        $this->dispatch('success', 'Scan started');
    }

    /**
     * Ignores a vulnerability ID or all vulnerabilities of a package.
     *
     * @param  string  $kind  vulnerability|package
     * @param  string  $scope  resource|team
     * @param  string  $expires  30|90|never (days)
     * @param  string|null  $ownerUuid  On a service page: the application or database the finding belongs to.
     */
    public function ignore(string $target, string $kind, string $scope, string $expires, string $reason, ?string $ownerUuid = null): bool
    {
        $resource = $this->resource();
        $this->authorize('update', $resource);
        $teamId = $resource->team()?->id;
        abort_unless($teamId !== null, 404);

        if ($scope === 'team') {
            abort_unless(auth()->user()->isAdminOfTeam($teamId), 403);
        }

        $package = $kind === 'package' ? (preg_replace('/\p{Cc}/u', '', $target) ?? '') : null;
        $data = [
            'kind' => $kind,
            'scope' => $scope,
            'expires' => $expires,
            'vulnerability_id' => $kind === 'vulnerability' ? $target : null,
            'package' => $package,
            'reason' => trim($reason),
        ];
        Validator::make($data, [
            ...ImageScanIgnore::validationRules(),
            'kind' => ['required', 'in:vulnerability,package'],
            'scope' => ['required', 'in:resource,team'],
            'expires' => ['required', 'in:'.implode(',', self::EXPIRES)],
        ])->validate();

        $owner = $resource instanceof Service
            ? $this->owners()->first(fn (Model $owner): bool => $ownerUuid !== null && $owner->getAttribute('uuid') === $ownerUuid)
            : $resource;
        abort_unless($owner instanceof Model, 404);

        if (! $this->ownerHasFinding($owner, $kind, $kind === 'package' ? (string) $package : $target)) {
            Validator::make([], [])->after(fn ($validator) => $validator->errors()
                ->add($kind === 'package' ? 'package' : 'vulnerability_id', 'This is not in the scan results.'))->validate();
        }

        $ignore = ImageScanIgnore::query()->create([
            'team_id' => $teamId,
            'resource_type' => $scope === 'resource' ? $owner->getMorphClass() : null,
            'resource_id' => $scope === 'resource' ? $owner->getKey() : null,
            'vulnerability_id' => $data['vulnerability_id'],
            'package' => $data['package'],
            'reason' => $data['reason'],
            'expires_at' => $expires === 'never' ? null : now()->addDays((int) $expires),
            'created_by' => auth()->id(),
        ]);

        ResourceScans::refreshSummaries(ResourceScans::current($resource, $this->owners()));

        auditLog('ui.image_scan.ignore_added', [
            'team_id' => $teamId,
            'resource_type' => $ignore->resource_type,
            'resource_uuid' => $scope === 'resource' ? $owner->getAttribute('uuid') : null,
            'vulnerability_id' => $ignore->vulnerability_id,
            'package' => $ignore->package,
            'expires_at' => $ignore->expires_at?->toIso8601String(),
        ]);
        $this->dispatch('success', 'Ignored');

        return true;
    }

    /**
     * Loads the resource from the locked identifiers, only in the user's teams, and checks that the user may see it.
     */
    private function resource(): Model
    {
        if ($this->loadedResource !== null) {
            return $this->loadedResource;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $resource = ResourceScans::find($this->resourceType, $this->resourceUuid, $user->teams->pluck('id')->all());
        abort_unless($resource instanceof Model, 404);
        $this->authorize('view', $resource);

        return $this->loadedResource = $resource;
    }

    /**
     * @return Collection<int, Model>
     */
    private function owners(): Collection
    {
        return $this->loadedOwners ??= ResourceScans::owners($this->resource());
    }

    /**
     * @return list<string>
     */
    private function applicationImageRefs(Application $application): array
    {
        $ref = ResourceScans::currentImageRef($application);
        if ($ref !== null) {
            return [$ref];
        }

        return ResourceScans::currentFor($application, ['id', 'resource_type', 'resource_id', 'deployment_queue_id', 'image_ref', 'scanned_at'])
            ->pluck('image_ref')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function ownerHasFinding(Model $owner, string $kind, string $target): bool
    {
        $target = $kind === 'package' ? $target : mb_strtolower($target);

        foreach (ResourceScans::currentFor($owner) as $scan) {
            foreach ((array) $scan->findings as $row) {
                $finding = Finding::fromArray((array) $row);
                $matches = $kind === 'package'
                    ? $finding->package === $target
                    : in_array($target, array_map('mb_strtolower', [$finding->id, ...$finding->aliases]), true);

                if ($matches) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The scan progress of the first owner that is being scanned.
     *
     * @param  Collection<int, Model>  $owners
     * @return array<string, mixed>|null
     */
    private function progress(Collection $owners): ?array
    {
        foreach ($owners as $owner) {
            $progress = Cache::get(ScanImage::progressCacheKey($owner));
            if (is_array($progress)) {
                return $progress;
            }
        }

        return null;
    }

    /**
     * The last scan errors of the owners, as their fixed messages.
     *
     * @param  Collection<int, Model>  $owners
     * @return list<string>
     */
    private function errorMessages(Collection $owners): array
    {
        $messages = [];
        foreach ($owners as $owner) {
            $error = Cache::get(ScanImage::lastErrorCacheKey($owner));
            $code = is_array($error) ? ImageScanErrorCode::tryFrom((string) ($error['code'] ?? '')) : null;
            if ($code !== null) {
                $messages[] = $code->message((array) ($error['context'] ?? []));
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * One row per finding of the current scans, with its fix state and the ignore that matches it.
     *
     * @param  Collection<int, ImageScan>  $scans
     * @return list<array{key: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string}>
     */
    private function findingRows(Collection $scans): array
    {
        $rows = [];
        $matchers = [];

        foreach ($scans as $scan) {
            $owner = $scan->resource;
            $ownerKey = $owner->getMorphClass().':'.$owner->getKey();
            $matchers[$ownerKey] ??= IgnoreMatcher::forResource($owner);
            $classify = ImageScanGate::classifierFor($scan);

            foreach ((array) $scan->findings as $index => $row) {
                $finding = Finding::fromArray((array) $row);
                $rows[] = [
                    'key' => $scan->uuid.'-'.$index,
                    'finding' => $finding,
                    'state' => $classify($finding)->value,
                    'ignore' => $matchers[$ownerKey]->matches($finding),
                    'image' => (string) $scan->image_ref,
                    'owner' => (string) $owner->getAttribute('uuid'),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{key: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string}>  $rows
     * @return list<array{key: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string}>
     */
    private function filterRows(array $rows): array
    {
        $search = mb_strtolower(trim($this->search));
        $severities = array_values(array_intersect($this->severities, self::SEVERITIES));

        return array_values(array_filter($rows, function (array $row) use ($search, $severities): bool {
            $finding = $row['finding'];

            if ($severities !== [] && ! in_array($this->severityOf($finding), $severities, true)) {
                return false;
            }

            if ($this->images !== [] && ! in_array($row['image'], $this->images, true)) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', [$finding->id, ...$finding->aliases, $finding->package, $finding->version]));

            return str_contains($haystack, $search);
        }));
    }

    private function severityOf(Finding $finding): string
    {
        $severity = strtolower($finding->severity);

        return in_array($severity, self::SEVERITIES, true) ? $severity : 'unknown';
    }

    /**
     * Rows of the By package view: one per package version of an image, worst first.
     *
     * @param  list<array{key: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string}>  $rows
     * @return list<array<string, mixed>>
     */
    private function packageGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $finding = $row['finding'];
            $key = md5($row['owner']."\0".$row['image']."\0".$finding->package."\0".$finding->version);
            $groups[$key] ??= [
                'key' => $key,
                'package' => $finding->package,
                'version' => $finding->version,
                'type' => $finding->type,
                'image' => $row['image'],
                'owner' => $row['owner'],
                'severity' => 'unknown',
                'kev' => false,
                'fixed' => [],
                'rows' => [],
            ];

            $group = &$groups[$key];
            $group['rows'][] = $row;
            $group['kev'] = $group['kev'] || $finding->kev;
            if (self::SEVERITY_RANK[$this->severityOf($finding)] > self::SEVERITY_RANK[$group['severity']]) {
                $group['severity'] = $this->severityOf($finding);
            }
            if ($finding->fixedIn !== null && $finding->fixedIn !== '') {
                $group['fixed'][] = $finding->fixedIn;
            }
            unset($group);
        }

        $groups = array_map(function (array $group): array {
            $fixed = array_values(array_unique($group['fixed']));
            usort($fixed, fn (string $a, string $b): int => version_compare($b, $a) ?: strnatcmp($b, $a));
            $group['fixed'] = $fixed[0] ?? null;
            $group['count'] = count($group['rows']);

            return $group;
        }, array_values($groups));

        usort($groups, fn (array $a, array $b): int => [$b['kev'], self::SEVERITY_RANK[$b['severity']], $b['count'], $a['package']]
            <=> [$a['kev'], self::SEVERITY_RANK[$a['severity']], $a['count'], $b['package']]);

        return $groups;
    }

    /**
     * @param  list<array{key: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string}>  $rows
     * @return list<array{key: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string}>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => [$b['finding']->kev, self::SEVERITY_RANK[$this->severityOf($b['finding'])], $b['finding']->risk ?? 0.0]
            <=> [$a['finding']->kev, self::SEVERITY_RANK[$this->severityOf($a['finding'])], $a['finding']->risk ?? 0.0]);

        return $rows;
    }

    /**
     * @param  list<mixed>  $items
     */
    private function paginate(array $items): LengthAwarePaginator
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $lastPage = max(1, (int) ceil(count($items) / $this->perPage));
        $page = max(1, min((int) $this->getPage(), $lastPage));
        if ($page !== (int) $this->getPage()) {
            $this->setPage($page);
        }

        return new LengthAwarePaginator(
            collect($items)->forPage($page, $this->perPage)->values(),
            count($items),
            $this->perPage,
            $page,
        );
    }

    /**
     * The page state before results: instance off, server off, or app scan off.
     */
    private function offState(Model $resource, ?Server $server): ?string
    {
        return match (true) {
            ! instanceSettings()->is_image_scanning_allowed => 'instance',
            ! $server instanceof Server || ! $server->settings?->is_image_scan_enabled => 'server',
            $resource instanceof Application && ! $resource->settings?->is_image_scan_enabled => 'application',
            default => null,
        };
    }

    /**
     * @param  Collection<int, ImageScan>  $scans
     * @return array{name: string, version: string}|null
     */
    private function eolDistro(Collection $scans): ?array
    {
        foreach ($scans as $scan) {
            $eol = data_get($scan->summary, 'eol_distro');
            if (is_array($eol) && filled($eol['name'] ?? null)) {
                return ['name' => ucfirst((string) $eol['name']), 'version' => (string) ($eol['version'] ?? '')];
            }
        }

        return null;
    }

    public function render(): View
    {
        $resource = $this->resource();
        $owners = $this->owners();
        $server = ImageScanPolicyResolver::for($resource)->scanServer;
        $offState = $this->offState($resource, $server);
        $user = auth()->user();
        $canUpdate = $user->can('update', $resource);

        $base = [
            'offState' => $offState,
            'serverName' => (string) ($server?->name ?? 'this server'),
            'serverUrl' => $server instanceof Server && $user->can('update', $server)
                ? route('server.security.image-scanning', ['server_uuid' => $server->uuid])
                : null,
            'canUpdate' => $canUpdate,
        ];

        if ($offState !== null) {
            return view('livewire.image-scan.vulnerabilities', $base);
        }

        $this->fixState = ImageScanFixState::tryFrom($this->fixState)?->value ?? ImageScanFixState::FixableNow->value;
        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'package';

        $scans = ResourceScans::current($resource, $owners);
        $allRows = $this->findingRows($scans);
        $activeRows = array_values(array_filter($allRows, fn (array $row): bool => $row['ignore'] === null));
        $ignoredRows = array_values(array_filter($allRows, fn (array $row): bool => $row['ignore'] !== null));

        $fixableBySeverity = array_fill_keys(self::SEVERITIES, 0);
        foreach ($activeRows as $row) {
            if ($row['state'] === ImageScanFixState::FixableNow->value) {
                $fixableBySeverity[$this->severityOf($row['finding'])]++;
            }
        }

        $filteredRows = $this->filterRows($activeRows);
        $stateCounts = array_fill_keys(array_map(fn (ImageScanFixState $state) => $state->value, ImageScanFixState::cases()), 0);
        foreach ($filteredRows as $row) {
            $stateCounts[$row['state']]++;
        }

        if ($this->showIgnored) {
            $listRows = $this->sortRows($this->filterRows($ignoredRows));
            $items = $this->paginate($listRows);
        } else {
            $listRows = array_values(array_filter($filteredRows, fn (array $row): bool => $row['state'] === $this->fixState));
            $items = $this->paginate($this->view === 'package' ? $this->packageGroups($listRows) : $this->sortRows($listRows));
        }

        $scannedAt = $scans->max('scanned_at');
        $scannedAt = $scannedAt instanceof Carbon ? $scannedAt : null;
        $imageOptions = $scans->pluck('image_ref')->map(fn ($ref) => (string) $ref)->unique()->values()->all();

        return view('livewire.image-scan.vulnerabilities', [
            ...$base,
            'scans' => $scans,
            'progress' => $this->progress($owners),
            'errorMessages' => $this->errorMessages($owners),
            'runsOtherImage' => $scans->contains(fn (ImageScan $scan): bool => ResourceScans::runsOtherImage($scan, $scan->resource)),
            'scannedAt' => $scannedAt,
            'oldResultsDays' => $scannedAt !== null && $scannedAt->lt(now()->subDays(self::OLD_RESULTS_DAYS))
                ? (int) $scannedAt->diffInDays(now())
                : null,
            'eolDistro' => $this->eolDistro($scans),
            'truncated' => $scans->contains(fn (ImageScan $scan): bool => (bool) data_get($scan->summary, 'truncated', false)),
            'fixableBySeverity' => $fixableBySeverity,
            'stateCounts' => $stateCounts,
            'ignoredCount' => count($ignoredRows),
            'items' => $items,
            'imageOptions' => count($imageOptions) > 1 ? $imageOptions : [],
            'showsImage' => count($imageOptions) > 1,
            'isService' => $resource instanceof Service,
            'canIgnoreForTeam' => $canUpdate && $user->isAdminOfTeam((int) $resource->team()?->id),
            'hasFilters' => $this->severities !== [] || $this->images !== [] || trim($this->search) !== '',
            'severityOptions' => self::SEVERITIES,
        ]);
    }
}
