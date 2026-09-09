<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

class Resources extends Component
{
    use AuthorizesRequests;
    use WithPagination;

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

    public ?Server $server = null;

    public $parameters = [];

    public array $unmanagedContainers = [];

    public $activeTab = 'managed';

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'refreshStatus',
        ];
    }

    public function startUnmanaged($id)
    {
        try {
            $this->authorize('update', $this->server);
            if (! ValidationPatterns::isValidContainerName($id)) {
                $this->dispatch('error', 'Invalid container identifier.');

                return;
            }
            $this->server->startUnmanaged($id);
            $this->dispatch('success', 'Container started.');
            $this->loadUnmanagedContainers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restartUnmanaged($id)
    {
        try {
            $this->authorize('update', $this->server);
            if (! ValidationPatterns::isValidContainerName($id)) {
                $this->dispatch('error', 'Invalid container identifier.');

                return;
            }
            $this->server->restartUnmanaged($id);
            $this->dispatch('success', 'Container restarted.');
            $this->loadUnmanagedContainers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stopUnmanaged($id)
    {
        try {
            $this->authorize('update', $this->server);
            if (! ValidationPatterns::isValidContainerName($id)) {
                $this->dispatch('error', 'Invalid container identifier.');

                return;
            }
            $this->server->stopUnmanaged($id);
            $this->dispatch('success', 'Container stopped.');
            $this->loadUnmanagedContainers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshStatus()
    {
        $this->server->refresh();
        if ($this->activeTab === 'managed') {
            $this->loadManagedContainers();
        } else {
            $this->loadUnmanagedContainers();
        }
        $this->dispatch('success', 'Resource statuses refreshed.');
    }

    public function loadManagedContainers()
    {
        try {
            if ($this->activeTab !== 'managed') {
                $this->search = '';
                $this->resetPage();
            }
            $this->activeTab = 'managed';
            $this->server->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadUnmanagedContainers()
    {
        if ($this->activeTab !== 'unmanaged') {
            $this->search = '';
            $this->resetPage();
        }
        $this->activeTab = 'unmanaged';
        try {
            $this->unmanagedContainers = $this->server->loadUnmanagedContainers()->toArray();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $resources = $this->activeTab === 'managed'
            ? $this->server->definedResources()->sortBy('name', SORT_NATURAL)
            : collect($this->unmanagedContainers)->sortBy('Names', SORT_NATURAL);
        $search = trim($this->search);
        if ($search !== '') {
            $nameKey = $this->activeTab === 'managed' ? 'name' : 'Names';
            $resources = $resources->filter(fn ($resource) => str((string) data_get($resource, $nameKey))
                ->contains($search, ignoreCase: true));
        }
        $this->perPage = max(1, min(100, $this->perPage));
        $lastPage = max(1, (int) ceil($resources->count() / $this->perPage));
        $page = max(1, min((int) $this->getPage(), $lastPage));
        if ($page !== $this->getPage()) {
            $this->setPage($page);
        }

        return view('livewire.server.resources', [
            'resources' => new LengthAwarePaginator(
                $resources->forPage($page, $this->perPage)->values(),
                $resources->count(),
                $this->perPage,
                $page,
            ),
        ]);
    }
}
