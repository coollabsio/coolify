<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithFileUploads;
use Visus\Cuid2\Cuid2;

class ContainerFileBrowser extends Component
{
    use WithFileUploads;

    public $resource;

    public Collection $servers;

    public Collection $containers;

    public string $selectedContainer = '';

    public string $currentPath = '/';

    public array $entries = [];

    public array $breadcrumbs = [];

    public string $newFolderName = '';

    public $uploadFile;

    public bool $isLoading = false;

    public ?string $deleteTarget = null;

    public function mount($resource)
    {
        $this->resource = $resource;
        $this->containers = collect();
        $this->servers = collect();

        if ($this->resource instanceof Application) {
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            foreach ($this->resource->additional_servers as $server) {
                if ($server->isFunctional()) {
                    $this->servers = $this->servers->push($server);
                }
            }
        } elseif ($this->resource instanceof Service) {
            if ($this->resource->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->server);
            }
        } else {
            // Database types
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
        }

        $this->loadContainers();
    }

    public function loadContainers()
    {
        $this->containers = collect();
        foreach ($this->servers as $server) {
            if ($this->resource instanceof Application) {
                $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
                foreach ($containers as $container) {
                    if (data_get($container, 'State') === 'running') {
                        $this->containers->push([
                            'server' => $server,
                            'container' => $container,
                        ]);
                    }
                }
            } elseif ($this->resource instanceof Service) {
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
            } else {
                // Database types
                if ($this->resource->isRunning()) {
                    $this->containers->push([
                        'server' => $server,
                        'container' => ['Names' => $this->resource->uuid],
                    ]);
                }
            }
        }

        if ($this->containers->count() === 1) {
            $this->selectedContainer = data_get($this->containers->first(), 'container.Names');
            $this->loadDirectory();
        }
    }

    public function selectContainer(string $containerName)
    {
        $this->selectedContainer = $containerName;
        $this->currentPath = '/';
        $this->loadDirectory();
    }

    private function getServer(): ?Server
    {
        $container = $this->containers->firstWhere('container.Names', $this->selectedContainer);

        return data_get($container, 'server');
    }

    private function sanitizePath(string $path): string
    {
        // Normalize the path - resolve .. and . and prevent traversal
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return '/'.implode('/', $resolved);
    }

    public function loadDirectory()
    {
        if (empty($this->selectedContainer)) {
            return;
        }

        $server = $this->getServer();
        if (! $server) {
            $this->dispatch('error', 'Server not found.');

            return;
        }

        $this->isLoading = true;
        $safePath = escapeshellarg($this->currentPath);
        $safeContainer = escapeshellarg($this->selectedContainer);

        try {
            $output = instant_remote_process(
                ["docker exec {$safeContainer} ls -la --time-style=long-iso {$safePath} 2>/dev/null || echo 'COOLIFY_LS_ERROR'"],
                $server,
                throwError: false
            );

            if (str_contains($output, 'COOLIFY_LS_ERROR') || is_null($output)) {
                $this->dispatch('error', 'Could not read directory.');
                $this->entries = [];
                $this->isLoading = false;

                return;
            }

            $this->entries = $this->parseLsOutput($output);
            $this->buildBreadcrumbs();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to list directory: '.$e->getMessage());
            $this->entries = [];
        }

        $this->isLoading = false;
    }

    private function parseLsOutput(string $output): array
    {
        $lines = explode("\n", trim($output));
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip total line and empty lines
            if (empty($line) || str_starts_with($line, 'total ')) {
                continue;
            }

            // Parse ls -la output:
            // drwxr-xr-x 2 root root 4096 2024-01-15 10:30 dirname
            // -rw-r--r-- 1 root root 1234 2024-01-15 10:30 filename
            if (! preg_match('/^([dlcbsp\-][rwxsStT\-]{9})\s+\d+\s+(\S+)\s+(\S+)\s+(\d+)\s+(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})\s+(.+)$/', $line, $matches)) {
                continue;
            }

            $name = $matches[7];
            // Skip . and .. entries
            if ($name === '.' || $name === '..') {
                continue;
            }

            $permissions = $matches[1];
            $isDirectory = str_starts_with($permissions, 'd');
            $isSymlink = str_starts_with($permissions, 'l');
            $size = (int) $matches[4];

            // For symlinks, extract the target
            $symlinkTarget = null;
            if ($isSymlink && str_contains($name, ' -> ')) {
                [$name, $symlinkTarget] = explode(' -> ', $name, 2);
            }

            $entries[] = [
                'name' => $name,
                'permissions' => $permissions,
                'owner' => $matches[2],
                'group' => $matches[3],
                'size' => $size,
                'date' => $matches[5],
                'time' => $matches[6],
                'is_directory' => $isDirectory,
                'is_symlink' => $isSymlink,
                'symlink_target' => $symlinkTarget,
                'path' => rtrim($this->currentPath, '/').'/'.$name,
            ];
        }

        // Sort: directories first, then files, alphabetically
        usort($entries, function ($a, $b) {
            if ($a['is_directory'] !== $b['is_directory']) {
                return $b['is_directory'] <=> $a['is_directory'];
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    private function buildBreadcrumbs()
    {
        $parts = array_filter(explode('/', $this->currentPath));
        $this->breadcrumbs = [];
        $path = '';
        foreach ($parts as $part) {
            $path .= '/'.$part;
            $this->breadcrumbs[] = ['name' => $part, 'path' => $path];
        }
    }

    public function navigateTo(string $path)
    {
        $this->currentPath = $this->sanitizePath($path);
        $this->loadDirectory();
    }

    public function navigateUp()
    {
        if ($this->currentPath === '/') {
            return;
        }
        $this->currentPath = dirname($this->currentPath);
        if ($this->currentPath === '.') {
            $this->currentPath = '/';
        }
        $this->loadDirectory();
    }

    public function createFolder()
    {
        if (empty($this->newFolderName)) {
            $this->dispatch('error', 'Folder name cannot be empty.');

            return;
        }

        // Validate folder name - no slashes or special characters
        if (preg_match('/[\/\0]/', $this->newFolderName)) {
            $this->dispatch('error', 'Invalid folder name.');

            return;
        }

        $server = $this->getServer();
        if (! $server) {
            return;
        }

        $fullPath = rtrim($this->currentPath, '/').'/'.$this->newFolderName;
        $safePath = escapeshellarg($fullPath);
        $safeContainer = escapeshellarg($this->selectedContainer);

        try {
            instant_remote_process(
                ["docker exec {$safeContainer} mkdir -p {$safePath}"],
                $server
            );
            $this->newFolderName = '';
            $this->dispatch('success', 'Folder created successfully.');
            $this->loadDirectory();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create folder: '.$e->getMessage());
        }
    }

    public function confirmDelete(string $path)
    {
        $this->deleteTarget = $path;
    }

    public function cancelDelete()
    {
        $this->deleteTarget = null;
    }

    public function deleteEntry()
    {
        if (empty($this->deleteTarget)) {
            return;
        }

        $server = $this->getServer();
        if (! $server) {
            return;
        }

        $safePath = escapeshellarg($this->sanitizePath($this->deleteTarget));
        $safeContainer = escapeshellarg($this->selectedContainer);

        try {
            instant_remote_process(
                ["docker exec {$safeContainer} rm -rf {$safePath}"],
                $server
            );
            $this->deleteTarget = null;
            $this->dispatch('success', 'Deleted successfully.');
            $this->loadDirectory();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete: '.$e->getMessage());
        }
    }

    public function prepareDownload(string $path)
    {
        $server = $this->getServer();
        if (! $server) {
            $this->dispatch('error', 'Server not found.');

            return;
        }

        $sanitizedPath = $this->sanitizePath($path);
        $token = (string) new Cuid2;
        $tempPath = "/tmp/coolify-filebrowser-{$token}";
        $safePath = escapeshellarg($sanitizedPath);
        $safeContainer = escapeshellarg($this->selectedContainer);
        $safeTempPath = escapeshellarg($tempPath);

        try {
            instant_remote_process(
                ["docker cp {$safeContainer}:{$safePath} {$safeTempPath}"],
                $server
            );

            Cache::put("container-file-download:{$token}", [
                'server_id' => $server->id,
                'temp_path' => $tempPath,
                'filename' => basename($sanitizedPath),
                'team_id' => auth()->user()->currentTeam()->id,
            ], now()->addMinutes(5));

            $this->js("window.open('".route('download.container-file', ['token' => $token])."', '_blank')");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to prepare download: '.$e->getMessage());
        }
    }

    public function updatedUploadFile()
    {
        $this->uploadFileToContainer();
    }

    public function uploadFileToContainer()
    {
        if (! $this->uploadFile) {
            return;
        }

        $server = $this->getServer();
        if (! $server) {
            $this->dispatch('error', 'Server not found.');

            return;
        }

        $originalName = $this->uploadFile->getClientOriginalName();
        // Sanitize filename
        if (preg_match('/[\/\0]/', $originalName)) {
            $this->dispatch('error', 'Invalid filename.');

            return;
        }

        $token = (string) new Cuid2;
        $localTempPath = $this->uploadFile->getRealPath();
        $remoteTempPath = "/tmp/coolify-upload-{$token}";
        $containerDestPath = rtrim($this->currentPath, '/').'/'.$originalName;

        $safeContainer = escapeshellarg($this->selectedContainer);
        $safeRemoteTempPath = escapeshellarg($remoteTempPath);
        $safeContainerDestPath = escapeshellarg($containerDestPath);

        try {
            // Upload to server via SCP using the server's SSH key
            $privateKeyLocation = $server->privateKey->getKeyLocation();
            $sshPort = $server->port;
            $sshUser = $server->user;
            $sshHost = $server->ip;

            $scpCommand = "scp -P {$sshPort} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$privateKeyLocation} ".
                escapeshellarg($localTempPath)." {$sshUser}@{$sshHost}:{$remoteTempPath}";

            \Illuminate\Support\Facades\Process::timeout(120)->run($scpCommand);

            // docker cp into container
            instant_remote_process(
                [
                    "docker cp {$safeRemoteTempPath} {$safeContainer}:{$safeContainerDestPath}",
                    "rm -f {$safeRemoteTempPath}",
                ],
                $server
            );

            $this->uploadFile = null;
            $this->dispatch('success', 'File uploaded successfully.');
            $this->loadDirectory();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to upload file: '.$e->getMessage());
            // Cleanup remote temp file
            try {
                instant_remote_process(["rm -f {$safeRemoteTempPath}"], $server, throwError: false);
            } catch (\Throwable) {
            }
        }
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 1).' '.$units[$i];
    }

    public function render()
    {
        return view('livewire.project.shared.container-file-browser');
    }
}
