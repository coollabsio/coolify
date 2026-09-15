<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Services\ContainerFilesystemService;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileBrowser extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    #[Locked]
    public $resource;

    #[Locked]
    public string $type = '';

    #[Locked]
    public bool $containerRunning = false;

    #[Locked]
    public ?string $container = null;

    /** @var array<int, string> */
    #[Locked]
    public array $availableContainers = [];

    public string $currentPath = '';

    /** @var array<int, array{name:string,type:string,size:int,mtime:int}> */
    public array $entries = [];

    public ?string $editingPath = null;

    public bool $editorOpen = false;

    public string $editorContent = '';

    public string $editorLanguage = '';

    public $upload;

    /** @var array<string, mixed> */
    public array $parameters = [];

    public function mount($resource = null): void
    {
        $this->parameters = get_route_parameters();
        $resource = $resource ?? $this->resolveResourceFromRoute();

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
        $this->guard();
        $this->currentPath = rtrim($this->currentPath, '/').'/'.$name;
        $this->loadEntries();
    }

    public function goTo(string $path): void
    {
        $this->guard();
        if (! str_starts_with($path, '/')) {
            return;
        }
        $this->currentPath = $path === '/' ? '/' : rtrim($path, '/');
        $this->loadEntries();
    }

    public function refresh(): void
    {
        $this->guard();
        $this->loadEntries();
    }

    public function createDirectory(string $name): void
    {
        $this->guard();
        try {
            $this->service()->makeDirectory($this->childPath($name));
            $this->loadEntries();
            $this->dispatch('success', 'Folder created.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function createFile(string $name): void
    {
        $this->guard();
        try {
            $this->service()->createFile($this->childPath($name));
            $this->loadEntries();
            $this->dispatch('success', 'File created.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function chmodEntry(string $name, string $mode): void
    {
        $this->guard();
        try {
            $this->service()->chmod($this->childPath($name), $mode);
            $this->loadEntries();
            $this->dispatch('success', 'Permissions updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function renameEntry(string $from, string $newName): void
    {
        $this->guard();
        try {
            $this->service()->rename($this->childPath($from), $this->childPath($newName));
            $this->loadEntries();
            $this->dispatch('success', 'Renamed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deleteEntry(string $name): void
    {
        $this->guard();
        try {
            $this->service()->delete($this->childPath($name));
            $this->loadEntries();
            $this->dispatch('success', 'Deleted.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function openEditor(string $name): void
    {
        $this->guard();
        $path = $this->childPath($name);
        try {
            // read() guards binary/oversized files internally and throws.
            $this->editorContent = $this->service()->read($path);
            $this->editorLanguage = $this->guessLanguage($name);
            $this->editingPath = $path;
            $this->editorOpen = true;
            // Push content + language into the mounted Monaco editor. It lives
            // behind wire:ignore, so a dispatched event is how it receives them.
            $this->dispatch('load-file-editor', content: $this->editorContent, language: $this->editorLanguage);
        } catch (\RuntimeException $e) {
            $this->dispatch('error', "Can't edit this file - it's binary or larger than 5 MB. Download it instead.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function saveEditor(): void
    {
        $this->guard();
        if (is_null($this->editingPath)) {
            return;
        }
        try {
            $this->service()->write($this->editingPath, $this->editorContent);
            $this->editingPath = null;
            $this->editorOpen = false;
            $this->editorContent = '';
            $this->loadEntries();
            $this->dispatch('success', 'Saved.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function closeEditor(): void
    {
        $this->editingPath = null;
        $this->editorOpen = false;
        $this->editorContent = '';
    }

    public function uploadFile(): void
    {
        $this->guard();
        $this->validate(['upload' => 'required|file|max:1048576']); // 1 GB (KB units)
        try {
            $localTmp = $this->upload->getRealPath();
            $filename = $this->sanitizeUploadName($this->upload->getClientOriginalName());
            $this->service()->upload($localTmp, $this->childPath($filename));
            $this->upload = null;
            $this->loadEntries();
            $this->dispatch('success', 'Uploaded.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function download(string $name): ?StreamedResponse
    {
        $this->guard();
        try {
            $localTmp = $this->service()->download($this->childPath($name));
        } catch (\Throwable $e) {
            handleError($e, $this);

            return null;
        }
        $downloadName = str_ends_with($localTmp, '.tar.gz') ? $name.'.tar.gz' : $name;

        return response()->streamDownload(function () use ($localTmp) {
            try {
                readfile($localTmp);
            } finally {
                @unlink($localTmp);
            }
        }, $downloadName)->deleteFileAfterSend();
    }

    /**
     * Reduce a client-supplied upload name to a single safe basename,
     * stripping any directory traversal before it reaches the container.
     */
    protected function sanitizeUploadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || preg_match('/^\.+$/', $name) === 1) {
            throw new \RuntimeException('Invalid file name.');
        }

        return $name;
    }

    /**
     * Map a filename to a Monaco language id for the editor. Falls back to
     * well-known whole-file names so extension-less files still highlight.
     */
    protected function guessLanguage(string $name): string
    {
        $extMap = [
            'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript', 'ts' => 'typescript',
            'json' => 'json', 'php' => 'php', 'py' => 'python', 'rb' => 'ruby', 'go' => 'go',
            'rs' => 'rust', 'java' => 'java', 'sh' => 'shell', 'bash' => 'shell', 'zsh' => 'shell',
            'yml' => 'yaml', 'yaml' => 'yaml', 'toml' => 'ini', 'ini' => 'ini', 'env' => 'ini',
            'conf' => 'ini', 'cnf' => 'ini', 'md' => 'markdown', 'markdown' => 'markdown', 'html' => 'html',
            'htm' => 'html', 'xml' => 'xml', 'svg' => 'xml', 'css' => 'css', 'scss' => 'scss',
            'sql' => 'sql', 'dockerfile' => 'dockerfile',
        ];

        $base = strtolower(basename($name));

        // Whole-name matches for common extension-less/dotfiles.
        $nameMap = [
            'dockerfile' => 'dockerfile', 'containerfile' => 'dockerfile',
            '.gitignore' => 'ini', '.dockerignore' => 'ini', '.editorconfig' => 'ini',
            '.npmrc' => 'ini', '.gitconfig' => 'ini',
            '.bashrc' => 'shell', '.bash_profile' => 'shell', '.bash_aliases' => 'shell',
            '.profile' => 'shell', '.zshrc' => 'shell', '.zprofile' => 'shell',
        ];
        if (isset($nameMap[$base])) {
            return $nameMap[$base];
        }
        if ($base === '.env' || str_starts_with($base, '.env.')) {
            return 'ini';
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $extMap[$ext] ?? '';
    }

    protected function childPath(string $name): string
    {
        return rtrim($this->currentPath, '/').'/'.$name;
    }

    protected function guard(): void
    {
        $this->authorize('canAccessTerminal');
        if (! $this->containerRunning || is_null($this->container)) {
            throw new \RuntimeException('Container is not running.');
        }
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
            'perms' => $e->perms,
            'owner' => $e->owner,
            'group' => $e->group,
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

    protected function resolveResourceFromRoute(): Model
    {
        $parameters = get_route_parameters();
        $teamId = data_get(auth()->user()->currentTeam(), 'id');

        if ($uuid = data_get($parameters, 'application_uuid')) {
            return Application::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail();
        }
        if ($uuid = data_get($parameters, 'service_uuid')) {
            return Service::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail();
        }
        if ($uuid = data_get($parameters, 'database_uuid')) {
            $resource = getResourceByUuid($uuid, $teamId);
            if (is_null($resource)) {
                abort(404);
            }

            return $resource;
        }

        abort(404);
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

    public function render(): View
    {
        return view('livewire.project.shared.file-browser');
    }
}
