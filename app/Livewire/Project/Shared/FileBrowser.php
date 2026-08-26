<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Services\ContainerFilesystemService;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class FileBrowser extends Component
{
    use AuthorizesRequests;

    public $resource;

    public string $type = '';

    public bool $containerRunning = false;

    public ?string $container = null;

    /** @var array<int, string> */
    public array $availableContainers = [];

    public string $currentPath = '';

    /** @var array<int, array{name:string,type:string,size:int,mtime:int}> */
    public array $entries = [];

    public function mount($resource): void
    {
        // Security gate first, before any server or container resolution.
        $this->authorize('canAccessTerminal');
        $this->authorize('view', $resource);

        $this->resource = $resource;
        $this->type = $this->resolveType($resource);
        $this->containerRunning = (bool) $resource->isRunning();

        if (! $this->containerRunning) {
            return;
        }

        $this->availableContainers = $this->resolveContainers();
        $this->container = $this->availableContainers[0] ?? null;

        if (is_null($this->container)) {
            $this->containerRunning = false;

            return;
        }

        $this->currentPath = $this->service()->defaultRoot();
        $this->loadEntries();
    }

    public function selectContainer(string $name): void
    {
        if (! in_array($name, $this->availableContainers, true)) {
            return;
        }
        $this->container = $name;
        $this->currentPath = $this->service()->defaultRoot();
        $this->loadEntries();
    }

    public function open(string $name): void
    {
        $this->currentPath = rtrim($this->currentPath, '/').'/'.$name;
        $this->loadEntries();
    }

    public function goTo(string $path): void
    {
        if (! str_starts_with($path, '/')) {
            return;
        }
        $this->currentPath = $path === '/' ? '/' : rtrim($path, '/');
        $this->loadEntries();
    }

    public function refresh(): void
    {
        $this->loadEntries();
    }

    /**
     * @return array<int, array{label:string,path:string}>
     */
    public function breadcrumbs(): array
    {
        $crumbs = [['label' => '/', 'path' => '/']];
        $accumulated = '';
        foreach (array_filter(explode('/', $this->currentPath)) as $segment) {
            $accumulated .= '/'.$segment;
            $crumbs[] = ['label' => $segment, 'path' => $accumulated];
        }

        return $crumbs;
    }

    protected function loadEntries(): void
    {
        $entries = $this->service()->list($this->currentPath);
        $this->entries = array_map(fn ($e) => [
            'name' => $e->name,
            'type' => $e->type,
            'size' => $e->size,
            'mtime' => $e->mtime,
        ], $entries);
    }

    protected function service(): ContainerFilesystemService
    {
        return new ContainerFilesystemService($this->server(), $this->container);
    }

    protected function server(): Server
    {
        return match ($this->type) {
            'service' => $this->resource->server,
            default => $this->resource->destination->server,
        };
    }

    protected function resolveType($resource): string
    {
        return match (true) {
            $resource instanceof Application => 'application',
            $resource instanceof Service => 'service',
            default => 'database',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function resolveContainers(): array
    {
        if ($this->type === 'service') {
            $names = [];
            $this->resource->applications()->get()->each(function ($application) use (&$names) {
                if ($application->isRunning()) {
                    $names[] = $application->name.'-'.$this->resource->uuid;
                }
            });
            $this->resource->databases()->get()->each(function ($database) use (&$names) {
                if ($database->isRunning()) {
                    $names[] = $database->name.'-'.$this->resource->uuid;
                }
            });

            return array_values(array_filter($names, [ValidationPatterns::class, 'isValidContainerName']));
        }

        if ($this->type === 'application') {
            $server = $this->server();
            $names = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: false)
                ->filter(fn ($c) => data_get($c, 'State') === 'running')
                ->map(fn ($c) => data_get($c, 'Names'))
                ->filter()
                ->values()
                ->all();

            return array_values(array_filter($names, [ValidationPatterns::class, 'isValidContainerName']));
        }

        // Standalone database: the container name is the resource uuid.
        $name = $this->resource->uuid;

        return ValidationPatterns::isValidContainerName($name) ? [$name] : [];
    }

    public function render()
    {
        return view('livewire.project.shared.file-browser');
    }
}
