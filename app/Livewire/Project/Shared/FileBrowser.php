<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;
use Visus\Cuid2\Cuid2;

class FileBrowser extends Component
{
    use WithFileUploads;

    public string $selected_container = 'default';

    public Collection $containers;

    public array $parameters;

    public $resource;

    public string $type;

    public Collection $servers;

    public string $currentPath = '/';

    public array $entries = [];

    public bool $isLoading = false;

    public bool $showCreateFolder = false;

    public string $newFolderName = '';

    public $uploadFile;

    public bool $isUploading = false;

    private const MAX_DOWNLOAD_SIZE = 100 * 1024 * 1024;

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $this->containers = collect();
        $this->servers = collect();

        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            foreach ($this->resource->additional_servers as $server) {
                if ($server->isFunctional()) {
                    $this->servers = $this->servers->push($server);
                }
            }
            $this->loadContainers();
        } elseif (data_get($this->parameters, 'database_uuid')) {
            $this->type = 'database';
            $resource = getResourceByUuid($this->parameters['database_uuid'], data_get(auth()->user()->currentTeam(), 'id'));
            if (is_null($resource)) {
                abort(404);
            }
            $this->resource = $resource;
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            $this->loadContainers();
        } elseif (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            if ($this->resource->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->server);
            }
            $this->loadContainers();
        }
    }

    public function loadContainers(): void
    {
        foreach ($this->servers as $server) {
            if (data_get($this->parameters, 'application_uuid')) {
                if ($server->isSwarm()) {
                    $containers = collect([
                        [
                            'Names' => $this->resource->uuid.'_'.$this->resource->uuid,
                        ],
                    ]);
                } else {
                    $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
                }
                foreach ($containers as $container) {
                    if (data_get($container, 'State') === 'running') {
                        $this->containers = $this->containers->push([
                            'server' => $server,
                            'container' => $container,
                        ]);
                    }
                }
            } elseif (data_get($this->parameters, 'database_uuid')) {
                if ($this->resource->isRunning()) {
                    $this->containers = $this->containers->push([
                        'server' => $server,
                        'container' => [
                            'Names' => $this->resource->uuid,
                        ],
                    ]);
                }
            } elseif (data_get($this->parameters, 'service_uuid')) {
                $this->resource->applications()->get()->each(function ($application) {
                    if ($application->isRunning()) {
                        $this->containers->push([
                            'server' => $this->resource->server,
                            'container' => [
                                'Names' => data_get($application, 'name').'-'.data_get($this->resource, 'uuid'),
                            ],
                        ]);
                    }
                });
                $this->resource->databases()->get()->each(function ($database) {
                    if ($database->isRunning()) {
                        $this->containers->push([
                            'server' => $this->resource->server,
                            'container' => [
                                'Names' => data_get($database, 'name').'-'.data_get($this->resource, 'uuid'),
                            ],
                        ]);
                    }
                });
            }
        }

        $this->containers = $this->containers->sortBy(fn ($container) => data_get($container, 'container.Names'));

        if ($this->containers->count() === 1) {
            $this->selected_container = data_get($this->containers->first(), 'container.Names');
            $this->browse('/');
        }
    }

    public function updatedSelectedContainer(): void
    {
        if ($this->selected_container !== 'default') {
            $this->browse('/');
        }
    }

    public function browse(string $path): void
    {
        if (! $this->validatePath($path)) {
            $this->dispatch('error', 'Invalid path.');

            return;
        }

        $resolved = $this->resolveContainerAndServer();
        if (is_null($resolved)) {
            return;
        }

        $this->isLoading = true;

        try {
            $escapedContainer = escapeshellarg($resolved['containerName']);
            $escapedPath = escapeshellarg($path);

            $output = instant_remote_process([
                "docker exec {$escapedContainer} ls -la {$escapedPath} 2>&1",
            ], $resolved['server']);

            $this->entries = $this->parseLsOutput($output);
            $this->currentPath = $path;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to browse: '.$e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    public function navigateTo(int $index): void
    {
        if (! isset($this->entries[$index]) || ! $this->entries[$index]['isDirectory']) {
            $this->dispatch('error', 'Invalid entry.');

            return;
        }

        $name = $this->entries[$index]['name'];
        $newPath = rtrim($this->currentPath, '/').'/'.ltrim($name, '/');
        $this->browse($newPath);
    }

    public function navigateUp(): void
    {
        if ($this->currentPath === '/') {
            return;
        }
        $parent = dirname($this->currentPath);
        $this->browse($parent);
    }

    public function createFolder(): void
    {
        if (empty(trim($this->newFolderName))) {
            $this->dispatch('error', 'Folder name cannot be empty.');

            return;
        }

        if (! preg_match('/^[a-zA-Z0-9._\-]+$/', $this->newFolderName)) {
            $this->dispatch('error', 'Folder name contains invalid characters.');

            return;
        }

        $resolved = $this->resolveContainerAndServer();
        if (is_null($resolved)) {
            return;
        }

        try {
            $folderPath = rtrim($this->currentPath, '/').'/'.$this->newFolderName;
            $escapedContainer = escapeshellarg($resolved['containerName']);
            $escapedPath = escapeshellarg($folderPath);

            instant_remote_process([
                "docker exec {$escapedContainer} mkdir -p {$escapedPath}",
            ], $resolved['server']);

            $this->newFolderName = '';
            $this->showCreateFolder = false;
            $this->dispatch('success', 'Folder created.');
            $this->browse($this->currentPath);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create folder: '.$e->getMessage());
        }
    }

    public function deleteEntry(int $index): void
    {
        if (! isset($this->entries[$index])) {
            $this->dispatch('error', 'Invalid entry.');

            return;
        }

        $name = $this->entries[$index]['name'];

        $resolved = $this->resolveContainerAndServer();
        if (is_null($resolved)) {
            return;
        }

        try {
            $entryPath = rtrim($this->currentPath, '/').'/'.$name;
            $escapedContainer = escapeshellarg($resolved['containerName']);
            $escapedPath = escapeshellarg($entryPath);

            instant_remote_process([
                "docker exec {$escapedContainer} rm -rf {$escapedPath}",
            ], $resolved['server']);

            $this->dispatch('success', 'Deleted successfully.');
            $this->browse($this->currentPath);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete: '.$e->getMessage());
        }
    }

    public function downloadFile(int $index): mixed
    {
        if (! isset($this->entries[$index]) || $this->entries[$index]['isDirectory']) {
            $this->dispatch('error', 'Invalid file.');

            return null;
        }

        $name = $this->entries[$index]['name'];

        $resolved = $this->resolveContainerAndServer();
        if (is_null($resolved)) {
            return null;
        }

        try {
            $filePath = rtrim($this->currentPath, '/').'/'.$name;
            $escapedContainer = escapeshellarg($resolved['containerName']);
            $escapedPath = escapeshellarg($filePath);

            $sizeOutput = instant_remote_process([
                "docker exec {$escapedContainer} stat -c %s {$escapedPath} 2>/dev/null || echo 0",
            ], $resolved['server'], throwError: false);

            $fileSize = (int) trim($sizeOutput);

            if ($fileSize > self::MAX_DOWNLOAD_SIZE) {
                $this->dispatch('error', 'File is too large to download via browser (max 100MB). Use the terminal instead.');

                return null;
            }

            $content = instant_remote_process([
                "docker exec {$escapedContainer} sh -c 'base64 {$escapedPath}'",
            ], $resolved['server']);

            $decoded = base64_decode(str_replace("\n", '', $content));

            return response()->streamDownload(function () use ($decoded) {
                echo $decoded;
            }, $name);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to download: '.$e->getMessage());

            return null;
        }
    }

    public function uploadToContainer(): void
    {
        if (is_null($this->uploadFile)) {
            $this->dispatch('error', 'No file selected.');

            return;
        }

        $resolved = $this->resolveContainerAndServer();
        if (is_null($resolved)) {
            return;
        }

        $this->isUploading = true;

        try {
            $originalName = $this->uploadFile->getClientOriginalName();
            if (! preg_match('/^[a-zA-Z0-9._\- ]+$/', $originalName)) {
                $this->dispatch('error', 'File name contains invalid characters.');

                return;
            }

            $uuid = (string) new Cuid2;
            $localPath = $this->uploadFile->store("tmp/filebrowser-{$uuid}");
            $fullLocalPath = storage_path('app/'.$localPath);

            $remoteTmpPath = "/tmp/coolify-upload-{$uuid}";
            $containerDest = rtrim($this->currentPath, '/').'/'.$originalName;
            $escapedContainer = escapeshellarg($resolved['containerName']);
            $escapedRemoteTmp = escapeshellarg($remoteTmpPath);
            $escapedContainerDest = escapeshellarg($containerDest);

            instant_scp($fullLocalPath, $remoteTmpPath, $resolved['server']);

            instant_remote_process([
                "docker cp {$escapedRemoteTmp} {$escapedContainer}:{$escapedContainerDest}",
            ], $resolved['server']);

            instant_remote_process([
                "rm -f {$escapedRemoteTmp}",
            ], $resolved['server']);

            @unlink($fullLocalPath);
            @rmdir(dirname($fullLocalPath));

            $this->uploadFile = null;
            $this->dispatch('success', 'File uploaded.');
            $this->browse($this->currentPath);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to upload: '.$e->getMessage());
        } finally {
            $this->isUploading = false;
        }
    }

    public function downloadFolder(int $index): mixed
    {
        if (! isset($this->entries[$index]) || ! $this->entries[$index]['isDirectory']) {
            $this->dispatch('error', 'Invalid folder.');

            return null;
        }

        $name = $this->entries[$index]['name'];

        $resolved = $this->resolveContainerAndServer();
        if (is_null($resolved)) {
            return null;
        }

        try {
            $folderPath = rtrim($this->currentPath, '/').'/'.$name;
            $escapedContainer = escapeshellarg($resolved['containerName']);
            $escapedPath = escapeshellarg($folderPath);

            $sizeOutput = instant_remote_process([
                "docker exec {$escapedContainer} sh -c 'du -sb {$escapedPath} 2>/dev/null | cut -f1 || echo 0'",
            ], $resolved['server'], throwError: false);

            $folderSize = (int) trim($sizeOutput);

            if ($folderSize > self::MAX_DOWNLOAD_SIZE) {
                $this->dispatch('error', 'Folder is too large to download via browser (max 100MB). Use the terminal instead.');

                return null;
            }

            $content = instant_remote_process([
                "docker exec {$escapedContainer} sh -c 'tar czf - -C ".escapeshellarg(dirname($folderPath)).' '.escapeshellarg($name)." | base64'",
            ], $resolved['server']);

            $decoded = base64_decode(str_replace("\n", '', $content));

            return response()->streamDownload(function () use ($decoded) {
                echo $decoded;
            }, $name.'.tar.gz');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to download folder: '.$e->getMessage());

            return null;
        }
    }

    private function resolveContainerAndServer(): ?array
    {
        if ($this->selected_container === 'default') {
            $this->dispatch('error', 'Please select a container.');

            return null;
        }

        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $this->selected_container)) {
            $this->dispatch('error', 'Invalid container name.');

            return null;
        }

        $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
        if (is_null($container)) {
            $this->dispatch('error', 'Container not found.');

            return null;
        }

        $server = data_get($container, 'server');
        if (! $server || ! $server instanceof Server) {
            $this->dispatch('error', 'Invalid server configuration.');

            return null;
        }

        if ($server->isForceDisabled()) {
            $this->dispatch('error', 'Server is disabled.');

            return null;
        }

        return [
            'containerName' => data_get($container, 'container.Names'),
            'server' => $server,
        ];
    }

    private function validatePath(string $path): bool
    {
        if (! str_starts_with($path, '/')) {
            return false;
        }

        if (str_contains($path, '..')) {
            return false;
        }

        if (preg_match('/[`$|;&<>!\\\]/', $path)) {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, array{permissions: string, links: int, owner: string, group: string, size: int, modified: string, name: string, isDirectory: bool, isSymlink: bool, linkTarget: ?string}>
     */
    private function parseLsOutput(?string $output): array
    {
        if (empty($output)) {
            return [];
        }

        $lines = explode("\n", trim($output));
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, 'total ')) {
                continue;
            }

            if (preg_match('/^([d\-lbcps][rwxsStT\-]{9})\s+(\d+)\s+(\S+)\s+(\S+)\s+([\d,]+)\s+(.{12,18})\s+(.+)$/', $line, $matches)) {
                $name = $matches[7];
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $isSymlink = str_starts_with($matches[1], 'l');
                $linkTarget = null;
                if ($isSymlink && str_contains($name, ' -> ')) {
                    [$name, $linkTarget] = explode(' -> ', $name, 2);
                }

                $sizeStr = str_replace(',', '', $matches[5]);

                $entries[] = [
                    'permissions' => $matches[1],
                    'links' => (int) $matches[2],
                    'owner' => $matches[3],
                    'group' => $matches[4],
                    'size' => (int) $sizeStr,
                    'modified' => trim($matches[6]),
                    'name' => $name,
                    'isDirectory' => str_starts_with($matches[1], 'd'),
                    'isSymlink' => $isSymlink,
                    'linkTarget' => $linkTarget,
                ];
            }
        }

        usort($entries, function ($a, $b) {
            if ($a['isDirectory'] !== $b['isDirectory']) {
                return $b['isDirectory'] <=> $a['isDirectory'];
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    public function render()
    {
        return view('livewire.project.shared.file-browser');
    }
}
