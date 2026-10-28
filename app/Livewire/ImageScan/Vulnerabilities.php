<?php

namespace App\Livewire\ImageScan;

use App\Actions\ImageScan\ScanImage;
use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanFixState;
use App\Jobs\RefreshImageScanSummariesJob;
use App\Jobs\ScanResourceImagesJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\ImageScanPolicyResolver;
use App\Services\ImageScan\ImageUpdateRefused;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\SecurityAction;
use App\Services\ImageScan\SecurityActions;
use App\Services\ImageScan\SecurityCard;
use App\Services\ImageScan\SecurityRules;
use App\Services\ImageScan\SecurityStatus;
use App\Traits\ListensToTeamChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The Security page of a resource (spec 15.7): the main card with the status, the sentence, and the action that fixes
 * the most, then the findings of the images the resource runs (spec 6.2). The resource is reloaded and authorized on
 * every request.
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

    private const CHECKPOINTS = ['Get scanner', 'Update database', 'Read image', 'Find vulnerabilities'];

    /** Progress stage of ScanImage => index in CHECKPOINTS. */
    private const STAGE_CHECKPOINT = ['database' => 1, 'inspect' => 2, 'sbom' => 2, 'vulnerabilities' => 3];

    /** The fix states of the findings table: a fixed version exists (or an action fixes it), or none exists yet. */
    public const FIX_STATES = ['fixed' => 'Fixed by an update', 'no_fix' => 'No fix yet'];

    #[Locked]
    public string $resourceType;

    #[Locked]
    public string $resourceUuid;

    public string $fixState = 'fixed';

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

    /** @var Collection<int, ImageScan>|null */
    private ?Collection $scans = null;

    /** @var list<array{key: string, scan: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string, base: ?string, never_allowed: bool, package_never_allowed: bool}>|null */
    private ?array $rows = null;

    /** @var array<string, IgnoreMatcher> */
    private array $matchers = [];

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
        $this->fixState = array_key_exists($state, self::FIX_STATES) ? $state : 'fixed';
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

    /**
     * The "All findings" link of an image row: the findings of that image only. The browser opens the section.
     */
    public function showImageFindings(string $image): void
    {
        $refs = $this->currentScans()->pluck('image_ref')->map(fn ($ref): string => (string) $ref)->unique();
        if (! $refs->contains($image)) {
            return;
        }

        $this->images = [$image];
        $this->showIgnored = false;
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
     * Updates the image to a candidate of an action of the main card. The confirmation modal passes the password field
     * last; it is not used.
     *
     * @param  string|null  $ownerUuid  On a service page: the application or database whose scan offers the candidate.
     */
    public function updateImage(string $candidateRef, ?string $ownerUuid = null): bool
    {
        $resource = $this->resource();
        $this->authorize('update', $resource);

        $target = $resource;
        if ($resource instanceof Service) {
            $target = $this->owners()->first(fn (Model $owner): bool => filled($ownerUuid) && $owner->getAttribute('uuid') === $ownerUuid);
            abort_unless($target instanceof Model, 404);
        }

        $refusal = $this->updateRefusal(collect(SecurityStatus::forResource($resource)->actions)->first(
            fn (SecurityAction $action): bool => $action->ref === $candidateRef
                || collect($action->parts)->contains(fn (array $part): bool => $part['ref'] === $candidateRef),
        ));
        if ($refusal !== null) {
            $this->dispatch('error', $refusal);

            return true;
        }

        try {
            UpdateResourceImage::run($target, $candidateRef, auth()->user());
        } catch (ImageUpdateRefused $e) {
            $this->dispatch('error', $e->getMessage());

            return true;
        } catch (AuthorizationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('error', 'The update could not start.');

            return true;
        }

        $this->loadedOwners = null;
        $this->scans = null;
        $this->rows = null;
        $this->dispatch('success', 'Update started.');

        return true;
    }

    /**
     * Runs a merged service update ("Update n8n and n8n runners to 2.10.4."): every image of the action in one change
     * and one restart. The action is looked up again from the current status by its key.
     */
    public function updateImages(string $actionKey): bool
    {
        $resource = $this->resource();
        $this->authorize('update', $resource);
        abort_unless($resource instanceof Service, 404);

        $action = collect(SecurityStatus::forResource($resource)->actions)
            ->first(fn (SecurityAction $action): bool => $action->parts !== [] && SecurityCard::key($action) === $actionKey);
        $refusal = $this->updateRefusal($action);
        if ($refusal !== null) {
            $this->dispatch('error', $refusal);

            return true;
        }

        $owners = $this->owners();
        $parts = [];
        foreach ($action->parts as $part) {
            $owner = $owners->first(fn (Model $owner): bool => $owner->getMorphClass() === $part['owner_type'] && (string) $owner->getKey() === (string) $part['owner_id']);
            abort_unless($owner instanceof Model && is_string($part['ref']), 404);
            $parts[] = ['owner' => $owner, 'ref' => $part['ref']];
        }

        try {
            UpdateResourceImage::make()->handleParts($resource, $parts, auth()->user());
        } catch (ImageUpdateRefused $e) {
            $this->dispatch('error', $e->getMessage());

            return true;
        } catch (AuthorizationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('error', 'The update could not start.');

            return true;
        }

        $this->loadedOwners = null;
        $this->scans = null;
        $this->rows = null;
        $this->dispatch('success', 'Update started.');

        return true;
    }

    /**
     * Why the page does not run an update: it is no longer an action of the current status, or the action has no
     * button (Coolify's own resources, a server another Coolify instance manages, an image set in a compose file).
     */
    private function updateRefusal(?SecurityAction $action): ?string
    {
        return match (true) {
            ! $action instanceof SecurityAction => 'This update is no longer offered. Reload the page.',
            ! $action->button => 'Coolify cannot run this update. Follow the steps on this page instead.',
            default => null,
        };
    }

    /**
     * Rebuilds the running commit of the app without cache and redeploys it (spec 15.5 `rebuild`), on the server and
     * destination of the deployment that runs now.
     */
    public function rebuild(): bool
    {
        $application = $this->resource();
        abort_unless($application instanceof Application, 404);
        $this->authorize('deploy', $application);
        abort_if((int) $application->getKey() === 0, 403, 'Coolify does not rebuild its own resources.');

        $running = SecurityCard::runningDeployment($application);
        if (! $running instanceof ApplicationDeploymentQueue) {
            $this->dispatch('error', 'Deploy the app first.');

            return true;
        }
        if (! SecurityCard::knowsRunningCommit($application, $running)) {
            $this->dispatch('error', 'Coolify does not know which commit runs now. Redeploy the app first.');

            return true;
        }

        $server = $this->deploymentServer($application, $running);
        if (! $server instanceof Server) {
            $this->dispatch('error', 'The server of the running deployment is no longer one of this app.');

            return true;
        }
        if ($server->isTransferredAway()) {
            $this->dispatch('error', 'Another Coolify instance manages this server now.');

            return true;
        }
        $destination = StandaloneDocker::query()
            ->whereKey($running->destination_id)
            ->where('server_id', $server->id)
            ->first();

        try {
            $result = queue_application_deployment(
                application: $application,
                deployment_uuid: new_public_id(),
                commit: $running->commit,
                force_rebuild: true,
                git_type: $running->git_type,
                server: $server,
                destination: $destination,
                only_this_server: (bool) $running->only_this_server,
                docker_registry_image_tag: $running->docker_registry_image_tag,
            );
        } catch (AuthorizationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('error', 'The rebuild could not start.');

            return true;
        }

        if (($result['status'] ?? null) !== 'queued') {
            $this->dispatch('error', 'The rebuild did not start.', (string) ($result['message'] ?? ''));

            return true;
        }

        auditLog('ui.image_scan.rebuild', [
            'team_id' => $application->team()?->id,
            'resource_type' => $application->getMorphClass(),
            'resource_uuid' => (string) $application->uuid,
            'commit' => $running->commit,
        ]);
        $this->dispatch('success', 'Rebuild started.');

        return true;
    }

    /**
     * The server of a deployment when it is the destination server or an additional server of the application.
     */
    private function deploymentServer(Application $application, ApplicationDeploymentQueue $deployment): ?Server
    {
        return collect([$application->destination?->server])
            ->concat($application->additional_servers()->get())
            ->filter(fn ($server): bool => $server instanceof Server)
            ->first(fn (Server $server): bool => (int) $server->id === (int) $deployment->server_id);
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
        $this->validateOrToast(Validator::make($data, [
            ...ImageScanIgnore::validationRules(),
            'kind' => ['required', 'in:vulnerability,package'],
            'scope' => ['required', 'in:resource,team'],
            'expires' => ['required', 'in:'.implode(',', self::EXPIRES)],
        ]));

        $owner = $resource instanceof Service
            ? $this->owners()->first(fn (Model $owner): bool => $ownerUuid !== null && $owner->getAttribute('uuid') === $ownerUuid)
            : $resource;
        abort_unless($owner instanceof Model, 404);

        $matching = $this->ownerFindings($owner, $kind, $kind === 'package' ? (string) $package : $target);
        if ($matching === []) {
            $this->validateOrToast(Validator::make([], [])->after(fn ($validator) => $validator->errors()
                ->add($kind === 'package' ? 'package' : 'vulnerability_id', 'This is not in the scan results.')));
        }

        // Spec 15.2: an ignore of a problem with an exploit signal expires after at most 90 days.
        if ($expires === 'never' && collect($matching)->contains(fn (Finding $finding): bool => self::hasExploitSignal($finding))) {
            $this->validateOrToast(Validator::make([], [])->after(fn ($validator) => $validator->errors()
                ->add('expires', 'Attackers are using or are likely to use this problem, so its ignore must expire. Choose 30 or 90 days.')));
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

        $this->rows = null;
        $this->matchers = [];
        ResourceScans::refreshSummaries($this->currentScans());
        if ($scope === 'team') {
            RefreshImageScanSummariesJob::dispatch($teamId);
        }

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
     * Validates, and shows the first error as a toast too, because the ignore modal does not render server errors.
     */
    private function validateOrToast(\Illuminate\Validation\Validator $validator): void
    {
        try {
            $validator->validate();
        } catch (ValidationException $e) {
            $this->dispatch('error', $e->validator->errors()->first());

            throw $e;
        }
    }

    /**
     * The current scans of the page, loaded once per request.
     *
     * @return Collection<int, ImageScan>
     */
    private function currentScans(): Collection
    {
        return $this->scans ??= ResourceScans::current($this->resource(), $this->owners());
    }

    private function matcherFor(Model $owner): IgnoreMatcher
    {
        return $this->matchers[$owner->getMorphClass().':'.$owner->getKey()] ??= IgnoreMatcher::forResource($owner);
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

    /**
     * The findings of the owner's current scans that an ignore of $target would match.
     *
     * @return list<Finding>
     */
    private function ownerFindings(Model $owner, string $kind, string $target): array
    {
        $target = $kind === 'package' ? $target : mb_strtolower($target);
        $scans = $this->currentScans()->filter(fn (ImageScan $scan): bool => $scan->resource->is($owner));

        $matching = [];
        foreach ($scans as $scan) {
            foreach (ResourceScans::findings($scan) as $finding) {
                $matches = $kind === 'package'
                    ? $finding->package === $target
                    : in_array($target, array_map('mb_strtolower', [$finding->id, ...$finding->aliases]), true);

                if ($matches) {
                    $matching[] = $finding;
                }
            }
        }

        return $matching;
    }

    /**
     * A finding whose ignore must expire (spec 15.2): an exploit signal, and not a CPE-only OS match.
     */
    private static function hasExploitSignal(Finding $finding): bool
    {
        return SecurityRules::exploitSignal($finding) !== null && ! SecurityRules::isCpeOnlyOsMatch($finding);
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
     * The checkpoint list of a running scan. ScanImage reports the stages database, inspect, sbom (Syft reads the
     * image), and vulnerabilities (Grype). The scanner images are ready before the first stage.
     *
     * @param  array<string, mixed>|null  $progress
     * @return list<array{title: string, status: string}>
     */
    private function checkpoints(?array $progress): array
    {
        if ($progress === null) {
            return [];
        }

        $current = self::STAGE_CHECKPOINT[(string) ($progress['stage'] ?? '')] ?? 0;

        return array_map(fn (string $title, int $index): array => [
            'title' => $title,
            'status' => match (true) {
                $index < $current => 'success',
                $index === $current => 'running',
                default => 'pending',
            },
        ], self::CHECKPOINTS, array_keys(self::CHECKPOINTS));
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
     * One row per finding of the current scans, with its fix state and the ignore that matches it. Built once per
     * request.
     *
     * @return list<array{key: string, scan: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string, base: ?string, never_allowed: bool, package_never_allowed: bool}>
     */
    private function findingRows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $rows = [];
        foreach ($this->currentScans() as $scan) {
            $owner = $scan->resource;
            $matcher = $this->matcherFor($owner);
            $classify = SecurityActions::fixStates($scan, $matcher);
            $baseIds = $this->baseIds($scan);

            foreach (ResourceScans::findings($scan) as $index => $finding) {
                $fromBase = $baseIds !== [] && collect([$finding->id, ...$finding->aliases])
                    ->contains(fn (string $id): bool => isset($baseIds[mb_strtolower($id)]));
                $rows[] = [
                    'key' => $scan->uuid.'-'.$index,
                    'scan' => (string) $scan->uuid,
                    'finding' => $finding,
                    'state' => $classify($finding) === ImageScanFixState::NoFix ? 'no_fix' : 'fixed',
                    'ignore' => $matcher->matches($finding),
                    'image' => (string) $scan->image_ref,
                    'owner' => (string) $owner->getAttribute('uuid'),
                    'base' => $fromBase ? (string) $scan->base_image_ref : null,
                ];
            }
        }

        // The ignore modal offers "Never" only when no finding the ignore would match has an exploit signal.
        $signalIds = [];
        $signalPackages = [];
        foreach ($rows as $row) {
            if (self::hasExploitSignal($row['finding'])) {
                foreach ([$row['finding']->id, ...$row['finding']->aliases] as $id) {
                    $signalIds[$row['owner']][mb_strtolower($id)] = true;
                }
                $signalPackages[$row['owner']][$row['finding']->package] = true;
            }
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['never_allowed'] = ! isset($signalIds[$row['owner']][mb_strtolower($row['finding']->id)]);
            $rows[$index]['package_never_allowed'] = ! isset($signalPackages[$row['owner']][$row['finding']->package]);
        }

        return $this->rows = $rows;
    }

    /**
     * The findings that are not ignored, per scan.
     *
     * @param  list<array{key: string, scan: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string, base: ?string, never_allowed: bool, package_never_allowed: bool}>  $activeRows
     * @return array<string, int>
     */
    private function findingCounts(array $activeRows): array
    {
        $counts = $this->currentScans()->mapWithKeys(fn (ImageScan $scan): array => [(string) $scan->uuid => 0])->all();
        foreach ($activeRows as $row) {
            $counts[$row['scan']] = ($counts[$row['scan']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The IDs of the findings that the current base image of a Dockerfile build has too, from the last base check.
     *
     * @return array<string, true>
     */
    private function baseIds(ImageScan $scan): array
    {
        $base = data_get($scan->candidates, 'base');
        if (blank($scan->base_image_ref) || ! is_array($base) || ($base['image'] ?? null) !== $scan->base_image_ref) {
            return [];
        }

        $ids = [];
        foreach ((array) ($base['attributable_ids'] ?? []) as $id) {
            if (is_string($id) && $id !== '') {
                $ids[mb_strtolower($id)] = true;
            }
        }

        return $ids;
    }

    /**
     * @param  list<array{key: string, scan: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string, base: ?string, never_allowed: bool, package_never_allowed: bool}>  $rows
     * @return list<array{key: string, scan: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string, base: ?string, never_allowed: bool, package_never_allowed: bool}>
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
     * @param  list<array{key: string, scan: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string, base: ?string, never_allowed: bool, package_never_allowed: bool}>  $rows
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
                'never_allowed' => $row['package_never_allowed'],
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
     * @param  list<array{key: string, scan: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string, base: ?string, never_allowed: bool, package_never_allowed: bool}>  $rows
     * @return list<array{key: string, scan: string, finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, owner: string, base: ?string, never_allowed: bool, package_never_allowed: bool}>
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
        return ImageScanPolicyResolver::offState($resource, $server);
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

        $this->fixState = array_key_exists($this->fixState, self::FIX_STATES) ? $this->fixState : 'fixed';
        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'package';

        $scans = $this->currentScans();
        $allRows = $this->findingRows();
        $activeRows = array_values(array_filter($allRows, fn (array $row): bool => $row['ignore'] === null));
        $ignoredRows = array_values(array_filter($allRows, fn (array $row): bool => $row['ignore'] !== null));

        $filteredRows = $this->filterRows($activeRows);
        $stateCounts = array_fill_keys(array_keys(self::FIX_STATES), 0);
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

        $progress = $this->progress($owners);
        // One status per image, with the matchers of the findings table, shared by the resource status and the rows.
        $imageStatuses = $scans->mapWithKeys(fn (ImageScan $scan): array => [(string) $scan->uuid => SecurityStatus::forScan($scan, $this->matcherFor($scan->resource))])->all();
        $security = $scans->isEmpty() ? null : SecurityStatus::forResource($resource, $scans, $imageStatuses);
        $checkedAt = $security?->checkedAt ?? $scans->max('scanned_at');
        $imageOptions = $scans->pluck('image_ref')->map(fn ($ref) => (string) $ref)->unique()->values()->all();

        return view('livewire.image-scan.vulnerabilities', [
            ...$base,
            'scans' => $scans,
            'progress' => $progress,
            'checkpoints' => $this->checkpoints($progress),
            'errorMessages' => $this->errorMessages($owners),
            'card' => $security === null ? null : SecurityCard::for($resource, $security, $owners, $scans, $user, $this->findingCounts($activeRows), $imageStatuses, fn (Model $owner): IgnoreMatcher => $this->matcherFor($owner)),
            'hasSettings' => $resource instanceof Application,
            'checkedAt' => $checkedAt === null ? null : Carbon::instance($checkedAt),
            'truncated' => $scans->contains(fn (ImageScan $scan): bool => (bool) data_get($scan->summary, 'truncated', false)),
            'findingCount' => count($activeRows),
            'stateCounts' => $stateCounts,
            'fixStates' => self::FIX_STATES,
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
