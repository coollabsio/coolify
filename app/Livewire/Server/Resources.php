<?php

namespace App\Livewire\Server;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Server;
use App\Support\ValidationPatterns;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

class Resources extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use RemembersCanvasView;
    use WithPagination;

    public int $perPage = 10;

    public string $search = '';

    public string $sort = 'name_asc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $this->resetPage();
    }

    public function setSort(string $sort): void
    {
        if (! preg_match('/^(\w+)_(asc|desc)$/', $sort, $matches) || ! array_key_exists($matches[1], $this->sortColumns())) {
            return;
        }

        $this->sort = $sort;
        $this->resetPage();
    }

    /**
     * @return array<string, \Closure(mixed): string>
     */
    private function sortColumns(): array
    {
        if ($this->activeTab === 'managed') {
            return [
                'name' => fn ($resource): string => (string) data_get($resource, 'name'),
                'project' => fn ($resource): string => (string) data_get($resource->project(), 'name'),
                'environment' => fn ($resource): string => (string) data_get($resource, 'environment.name'),
                'type' => fn ($resource): string => (string) $resource->type(),
                'status' => fn ($resource): string => (string) data_get($resource, 'status'),
            ];
        }

        return [
            'name' => fn ($container): string => (string) data_get($container, 'Names'),
            'image' => fn ($container): string => (string) data_get($container, 'Image'),
            'status' => fn ($container): string => (string) data_get($container, 'State'),
        ];
    }

    public ?Server $server = null;

    public $parameters = [];

    public array $unmanagedContainers = [];

    public $activeTab = 'managed';

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'ApplicationStatusChanged' => 'refreshStatus',
        ]);
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
                $this->sort = 'name_asc';
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
            $this->sort = 'name_asc';
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
            if ($this->prefersCanvasView('server_resources')) {
                return $this->redirectRoute('server.canvas', ['server_uuid' => $this->server->uuid], navigate: true);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $columns = $this->sortColumns();
        [$column, $direction] = explode('_', $this->sort, 2) + [1 => 'asc'];
        $sortValue = $columns[$column] ?? $columns['name'];
        $resources = ($this->activeTab === 'managed'
            ? $this->server->definedResources()
            : collect($this->unmanagedContainers))
            ->sortBy($columns['name'], SORT_NATURAL | SORT_FLAG_CASE)
            ->sortBy($sortValue, SORT_NATURAL | SORT_FLAG_CASE, $direction === 'desc');
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
