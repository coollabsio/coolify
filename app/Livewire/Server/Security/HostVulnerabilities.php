<?php

namespace App\Livewire\Server\Security;

use App\Jobs\ImageRescanJob;
use App\Jobs\ScanHostPackagesJob;
use App\Models\Server;
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
    use WithPagination;

    public Server $server;

    #[Locked]
    public bool $hostScanRunning = false;

    public int $perPage = 10;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $this->resetPage();
    }

    public function getListeners()
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

    public function scanHostPackages()
    {
        try {
            $this->authorize('update', $this->server);
            if ($this->server->isTransferredAway()) {
                throw new \RuntimeException('This server is managed by another Coolify instance.');
            }
            if (! ImageRescanJob::canRunOn($this->server)) {
                $this->dispatch('error', message: 'Turn on image scanning for this server first.');

                return;
            }
            ScanHostPackagesJob::dispatchManual($this->server);
            $this->hostScanRunning = true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render(): View
    {
        $hostScan = $this->server->hostScan()->first();
        $hostFindings = $hostScan?->findingList() ?? [];
        $search = trim($this->search);
        $matchingFindings = $search === '' ? $hostFindings : array_values(array_filter(
            $hostFindings,
            fn ($finding) => str(implode(' ', [$finding->displayId, $finding->id, ...$finding->aliases, $finding->package, $finding->title]))
                ->contains($search, ignoreCase: true),
        ));

        $this->perPage = max(1, min(100, $this->perPage));
        $lastPage = max(1, (int) ceil(count($matchingFindings) / $this->perPage));
        $page = max(1, min((int) $this->getPage(), $lastPage));
        if ($page !== $this->getPage()) {
            $this->setPage($page);
        }

        return view('livewire.server.security.host-vulnerabilities', [
            'hostScan' => $hostScan,
            'hostFindings' => $hostFindings,
            'shownHostFindings' => new LengthAwarePaginator(
                collect($matchingFindings)->forPage($page, $this->perPage)->values(),
                count($matchingFindings),
                $this->perPage,
                $page,
            ),
            'hostScanAvailable' => ImageRescanJob::canRunOn($this->server),
            'result' => $this->server->settings->patch_check_result,
        ]);
    }
}
