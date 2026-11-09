<?php

namespace App\Livewire\Server\Security;

use App\Actions\Server\CheckUpdates;
use App\Jobs\ImageRescanJob;
use App\Jobs\ScanHostPackagesJob;
use App\Livewire\Concerns\ManagesServerPackageUpdates;
use App\Models\Server;
use App\Services\ImageScan\Finding;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Vulnerabilities in the operating system packages of a server (ScanHostPackages).
 */
class HostVulnerabilities extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use ManagesServerPackageUpdates;
    use WithPagination;

    public Server $server;

    #[Locked]
    public bool $hostScanRunning = false;

    private const SORT_COLUMNS = ['vulnerability', 'package', 'fixed', 'severity'];

    private const SEVERITY_ORDER = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public int $perPage = 10;

    /** `<column>_<asc|desc>`, or empty for the stored order: exploited first, then fixable, then risk. */
    #[Locked]
    public string $sortBy = '';

    public string $search = '';

    /** Shows the findings in installed kernels that the server does not run; they are no risk now, so they are hidden. */
    public bool $showOtherKernels = false;

    public function sortFindings(string $sortBy): void
    {
        [$column, $direction] = array_pad(explode('_', $sortBy, 2), 2, null);
        if (! in_array($column, self::SORT_COLUMNS, true) || ! in_array($direction, ['asc', 'desc'], true)) {
            return;
        }
        $this->sortBy = $sortBy;
        $this->resetPage();
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function sortedFindings(array $findings): array
    {
        if ($this->sortBy === '') {
            return $findings;
        }
        [$column, $direction] = explode('_', $this->sortBy, 2);
        $compare = match ($column) {
            'vulnerability' => fn (Finding $a, Finding $b): int => strnatcasecmp($a->displayId, $b->displayId),
            'package' => fn (Finding $a, Finding $b): int => strnatcasecmp($a->package, $b->package),
            'fixed' => fn (Finding $a, Finding $b): int => version_compare($a->fixedIn ?? '', $b->fixedIn ?? ''),
            'severity' => fn (Finding $a, Finding $b): int => (self::SEVERITY_ORDER[$a->severity] ?? 0) <=> (self::SEVERITY_ORDER[$b->severity] ?? 0),
        };
        // Findings without a fix stay last in both directions; usort keeps the stored order for equal values.
        usort($findings, function (Finding $a, Finding $b) use ($column, $compare, $direction): int {
            if ($column === 'fixed' && ($a->fixedIn === null) !== ($b->fixedIn === null)) {
                return $a->fixedIn === null ? 1 : -1;
            }

            return $direction === 'asc' ? $compare($a, $b) : $compare($b, $a);
        });

        return $findings;
    }

    public function updatedShowOtherKernels(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $this->resetPage();
    }

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ServerPatchCheckFinished' => 'scanFinished',
        ]);
    }

    public function mount(string $server_uuid): void
    {
        $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('viewSecurity', $this->server);
        $this->loadResult();
    }

    public function loadResult(): void
    {
        $this->server->settings->refresh();
        $this->hostScanRunning = Cache::has(ScanHostPackagesJob::runningCacheKey($this->server));
    }

    public function scanFinished(array $event = []): void
    {
        $serverUuid = data_get($event, 'serverUuid');
        if ($serverUuid !== null && $serverUuid !== $this->server->uuid) {
            return;
        }
        $this->loadResult();
    }

    public function scanHostPackages(): mixed
    {
        try {
            $this->authorize('update', $this->server);
            if ($this->server->isTransferredAway()) {
                throw new \RuntimeException('This server is managed by another Coolify instance.');
            }
            if (! ImageRescanJob::canRunOn($this->server)) {
                $this->dispatch('error', message: 'Turn on image scanning for this server first.');

                return null;
            }
            ScanHostPackagesJob::dispatchManual($this->server);
            $this->hostScanRunning = true;

            return null;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Updates the package of a host finding with the updates that the last patch check found for it.
     */
    public function updateVulnerablePackage(string $package): mixed
    {
        try {
            $this->authorize('update', $this->server);
            $isFinding = collect($this->server->hostScan()->first()?->findingList() ?? [])->contains(fn ($finding) => $finding->package === $package);
            $packages = array_column(CheckUpdates::fixingUpdates($package, $this->server->settings->patch_check_result), 'package');
            if (! $isFinding || $packages === []) {
                $this->dispatch('error', message: 'This package has no update in the last check. Run "Check for updates" on the Patching page.');

                return null;
            }
            $this->runUpdate(packages: $packages);

            return null;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render(): View
    {
        $hostScan = $this->server->hostScan()->first();
        $patchResult = $this->server->settings->patch_check_result;
        // The kernel that ran during the scan; the patch check can be older, for example from before a reboot.
        $runningKernel = data_get($hostScan?->summary, 'running_kernel') ?? data_get($patchResult, 'kernels.running');
        if (is_array($patchResult) && $runningKernel !== null) {
            data_set($patchResult, 'kernels.running', $runningKernel);
        }
        [$otherKernelFindings, $activeFindings] = collect($hostScan?->findingList() ?? [])
            ->partition(fn ($finding) => CheckUpdates::isOtherKernelPackage($finding->package, $finding->version, $runningKernel))
            ->map(fn ($findings) => $findings->values()->all());
        $hostFindings = $this->showOtherKernels ? [...$activeFindings, ...$otherKernelFindings] : $activeFindings;
        $installedKernels = collect(data_get($patchResult, 'kernels.installed', []));
        $runningKernelVersion = data_get($installedKernels->firstWhere('release', $runningKernel), 'version');
        $search = trim($this->search);
        $sortedFindings = $this->sortedFindings($hostFindings);
        $matchingFindings = $search === '' ? $sortedFindings : array_values(array_filter(
            $sortedFindings,
            fn ($finding) => str(implode(' ', [$finding->displayId, $finding->id, ...$finding->aliases, $finding->package, $finding->title]))
                ->contains($search, ignoreCase: true),
        ));

        $this->perPage = max(1, min(100, $this->perPage));
        $lastPage = max(1, (int) ceil(count($matchingFindings) / $this->perPage));
        $page = max(1, min((int) $this->getPage(), $lastPage));
        if ($page !== $this->getPage()) {
            $this->setPage($page);
        }

        $kernelStatusCounts = collect($activeFindings)
            ->map(fn ($finding) => CheckUpdates::kernelStatus($finding->package, $finding->version, $finding->fixedIn, $patchResult))
            ->filter()
            ->countBy();

        return view('livewire.server.security.host-vulnerabilities', [
            'kernelStatusCounts' => $kernelStatusCounts,
            'otherKernelCount' => max(count($otherKernelFindings), (int) data_get($hostScan?->summary, 'other_kernels', 0)),
            'hasOldKernels' => $runningKernelVersion !== null && $installedKernels->contains(fn ($kernel) => version_compare($kernel['version'], $runningKernelVersion, '<')),
            'runningKernel' => $runningKernel,
            'bootKernel' => data_get(CheckUpdates::bootKernel($patchResult), 'release'),
            'hostScan' => $hostScan,
            'hostFindings' => $hostFindings,
            'shownHostFindings' => new LengthAwarePaginator(
                collect($matchingFindings)->forPage($page, $this->perPage)->values(),
                count($matchingFindings),
                $this->perPage,
                $page,
            ),
            'hostScanAvailable' => ImageRescanJob::canRunOn($this->server),
            'result' => $patchResult,
        ]);
    }
}
