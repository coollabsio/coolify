<?php

namespace App\Livewire\Project\Shared;

use App\Exceptions\ContainerShellUnavailableException;
use App\Http\Controllers\FileBrowserDownloadController;
use App\Jobs\PrepareFileDownloadJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Services\ContainerFilesystemService;
use App\Services\FileDownloadStorageService;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class FileBrowser extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    #[Locked]
    public Model $resource;

    /** Uploads to S3 hold a queue worker, so each user may only start a few. */
    private const MAX_PENDING_S3_DOWNLOADS = 3;

    #[Locked]
    public string $type = '';

    #[Locked]
    public bool $containerRunning = false;

    #[Locked]
    public bool $filesLoaded = false;

    #[Locked]
    public bool $loadFailed = false;

    /** The container has no usable shell, so a helper container reaches its files. */
    #[Locked]
    public bool $useHelper = false;

    #[Locked]
    public bool $shellUnavailable = false;

    /** Why the helper container failed, shown when the reason is known. */
    #[Locked]
    public ?string $helperError = null;

    #[Locked]
    public ?string $container = null;

    /** @var array<int, string> */
    #[Locked]
    public array $availableContainers = [];

    public string $currentPath = '';

    /** @var array<int, array{name:string,type:string,size:int,mtime:int}> */
    public array $entries = [];

    #[Locked]
    public ?string $editingPath = null;

    public bool $editorOpen = false;

    public string $editorContent = '';

    public string $editorLanguage = '';

    public ?TemporaryUploadedFile $upload = null;

    /** @var array<int, string> */
    #[Locked]
    public array $pendingDownloads = [];

    /** @var array<string, mixed> */
    public array $parameters = [];

    public function mount(?Model $resource = null): void
    {
        $this->parameters = get_route_parameters();
        $resource = $resource ?? $this->resolveResourceFromRoute();

        // Security gate first, before any server or container resolution.
        $this->authorize('canAccessTerminal');
        $this->authorize('view', $resource);
        abort_unless((int) $resource->environment->project->team_id === (int) auth()->user()->currentTeam()->id, 403);

        $this->resource = $resource;
        $this->type = $this->resolveType($resource);
        $this->containerRunning = (bool) $resource->isRunning();

        $this->filesLoaded = ! $this->containerRunning;
    }

    public function loadFiles(): void
    {
        $this->authorizeFileAccess();
        if ($this->filesLoaded) {
            return;
        }
        $this->loadFailed = false;
        $this->containerRunning = (bool) $this->resource->isRunning();

        try {
            $this->availableContainers = $this->containerRunning ? $this->resolveContainers() : [];
            $this->container = $this->availableContainers[0] ?? null;
            $this->useHelper = false;
            if (is_null($this->container)) {
                $this->containerRunning = false;
            } else {
                $this->currentPath = $this->service()->defaultRoot();
                $this->loadEntries();
            }
            $this->filesLoaded = true;
        } catch (\Throwable $e) {
            $this->container = null;
            $this->loadFailed = true;
            handleError($e, $this);
        }
    }

    public function selectContainer(string $name): void
    {
        $this->guard();
        if (! in_array($name, $this->availableContainers, true)) {
            return;
        }
        $this->closeEditor();
        $this->container = $name;
        $this->useHelper = false;
        $this->currentPath = $this->service()->defaultRoot();
        $this->loadEntries();
    }

    public function open(string $name): void
    {
        $this->guard();
        if ($this->isHelperSymlink($name)) {
            return;
        }
        $this->currentPath = $this->childPath($name);
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
        if ($this->isHelperSymlink($name)) {
            return;
        }
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

    public function download(string $name): void
    {
        $this->guard();
        if ($this->isHelperSymlink($name)) {
            return;
        }
        try {
            $path = $this->childPath($name);
            $target = $this->service()->downloadInfo($path);
            $downloadName = $target['isDir'] ? $name.'.tar.gz' : $name;
            $s3Storage = FileDownloadStorageService::configuredStorage();
            $maxSize = $s3Storage ? FileDownloadStorageService::MAX_SIZE : ContainerFilesystemService::MAX_DOWNLOAD_SIZE;
            if ($target['size'] > $maxSize) {
                $this->dispatch('error', ($target['isDir'] ? 'This folder' : 'This file').' is larger than '.formatBytes($maxSize).' and cannot be downloaded here. Use the terminal or a volume backup instead.');

                return;
            }

            if ($s3Storage) {
                if (count($this->pendingDownloads) >= self::MAX_PENDING_S3_DOWNLOADS
                    || ! RateLimiter::attempt('file-browser-s3-download:'.auth()->id(), 10, fn () => true, 3600)) {
                    $this->dispatch('error', 'Too many downloads are in progress. Wait for them to finish, then try again.');

                    return;
                }
                $downloadId = (string) Str::uuid();
                $this->pendingDownloads[] = $downloadId;
                PrepareFileDownloadJob::dispatch($downloadId, auth()->id(), $this->server()->id, $this->container, $path, $target['isDir'], $downloadName, $s3Storage->id, $this->useHelper);
                $this->dispatch('info', 'Preparing '.$downloadName.'. The download starts when it is ready.');

                return;
            }

            $token = Str::random(64);
            Cache::put(FileBrowserDownloadController::cacheKey($token), [
                'user_id' => auth()->id(),
                'resource_type' => $this->resource::class,
                'resource_id' => $this->resource->getKey(),
                'server_id' => $this->server()->id,
                'container' => $this->container,
                'path' => $path,
                'name' => $downloadName,
                'is_dir' => $target['isDir'],
                'use_helper' => $this->useHelper,
            ], FileBrowserDownloadController::TOKEN_TTL_SECONDS);

            $this->redirect(route('project.files.download', $token));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Polled while S3 downloads are prepared. Opens each finished link once.
     */
    public function checkDownloads(): void
    {
        $this->authorizeFileAccess();
        foreach ($this->pendingDownloads as $index => $downloadId) {
            $result = Cache::get(PrepareFileDownloadJob::cacheKey($downloadId));
            if (! is_array($result) || ($result['user_id'] ?? null) !== auth()->id()) {
                continue;
            }

            unset($this->pendingDownloads[$index]);
            Cache::forget(PrepareFileDownloadJob::cacheKey($downloadId));
            if ($result['status'] === 'ready') {
                $this->dispatch('open-file-download', url: $result['url']);
            } else {
                $this->dispatch('error', $result['message']);
            }
        }
        $this->pendingDownloads = array_values($this->pendingDownloads);
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
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Invalid file name.');
        }
        validateShellSafePath($name, 'file name');

        return rtrim($this->currentPath, '/').'/'.$name;
    }

    protected function authorizeFileAccess(): void
    {
        $this->authorize('canAccessTerminal');
        $this->resource->refresh();
        $this->authorize('view', $this->resource);
        abort_unless((int) $this->resource->environment->project->team_id === (int) auth()->user()->currentTeam()->id, 403);
    }

    protected function guard(): void
    {
        $this->authorizeFileAccess();
        $this->type = $this->resolveType($this->resource);
        $this->containerRunning = (bool) $this->resource->isRunning();
        $this->availableContainers = $this->containerRunning ? $this->resolveContainers() : [];
        if (! $this->containerRunning || is_null($this->container) || ! in_array($this->container, $this->availableContainers, true)) {
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
        $this->shellUnavailable = false;
        $this->helperError = null;
        try {
            $entries = $this->service()->list($this->currentPath);
        } catch (ContainerShellUnavailableException $e) {
            if (! $this->useHelper) {
                $this->useHelper = true;
                $this->loadEntries();

                return;
            }
            Log::warning('File browser helper container failed', ['container' => $this->container, 'error' => $e->getMessage()]);
            $entries = [];
            $this->shellUnavailable = true;
            if (preg_match('/Unable to find image|failed to resolve reference|pull access denied|manifest unknown/i', $e->getMessage())) {
                $this->helperError = 'The server could not pull the Coolify helper image ('.coolifyHelperImage().':'.getHelperVersion().'). Check that the server can reach the registry.';
            }
        }
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
        return new ContainerFilesystemService($this->server(), $this->container, $this->useHelper);
    }

    /**
     * The helper resolves absolute symlinks in its own file system, not in the
     * container, so it would show the wrong files. It cannot change them,
     * because its root file system is read-only.
     */
    protected function isHelperSymlink(string $name): bool
    {
        if (! $this->useHelper || ! collect($this->entries)->contains(fn (array $entry) => $entry['name'] === $name && $entry['type'] === 'symlink')) {
            return false;
        }
        $this->dispatch('error', 'Symlinks cannot be opened in a container without a shell. Open the target path instead.');

        return true;
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

    protected function resolveType(Model $resource): string
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
            $names = getCurrentApplicationContainerStatus($server, $this->resource, includePullrequests: false)
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
