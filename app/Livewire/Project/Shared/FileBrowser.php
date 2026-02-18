<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;

class FileBrowser extends Component
{
    use WithFileUploads;

    public ?string $type = null;

    public $resource;

    public Collection $servers;

    public Collection $containers;

    public string $selectedContainer = '';

    public string $currentPath = '/';

    public array $files = [];

    public bool $loading = false;

    public bool $connected = false;

    public $parameters;

    public $uploadFile;

    public string $newFolderName = '';

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    public function mount()
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
        } elseif (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            if ($this->resource->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->server);
            }
        }

        $this->loadContainers();

        if ($this->containers->count() === 1) {
            $this->selectedContainer = data_get($this->containers->first(), 'container.Names');
            $this->connect();
        }
    }

    public function loadContainers()
    {
        foreach ($this->servers as $server) {
            if (data_get($this->parameters, 'application_uuid')) {
                if ($server->isSwarm()) {
                    continue;
                }
                $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
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
                        'container' => ['Names' => $this->resource->uuid],
                    ]);
                }
            } elseif (data_get($this->parameters, 'service_uuid')) {
                $this->resource->applications()->get()->each(function ($application) {
                    if ($application->isRunning()) {
                        $this->containers->push([
                            'server' => $this->resource->server,
                            'container' => [
                                'Names' => data_get($application, 'name') . '-' . data_get($this->resource, 'uuid'),
                            ],
                        ]);
                    }
                });
                $this->resource->databases()->get()->each(function ($database) {
                    if ($database->isRunning()) {
                        $this->containers->push([
                            'server' => $this->resource->server,
                            'container' => [
                                'Names' => data_get($database, 'name') . '-' . data_get($this->resource, 'uuid'),
                            ],
                        ]);
                    }
                });
            }
        }

        $this->containers = $this->containers->sortBy(fn ($c) => data_get($c, 'container.Names'));
    }

    public function connect()
    {
        if (empty($this->selectedContainer)) {
            $this->dispatch('error', 'Please select a container.');

            return;
        }

        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $this->selectedContainer)) {
            $this->dispatch('error', 'Invalid container name.');

            return;
        }

        $container = $this->containers->firstWhere('container.Names', $this->selectedContainer);
        if (is_null($container)) {
            $this->dispatch('error', 'Container not found.');

            return;
        }

        $server = data_get($container, 'server');
        if (! $server || $server->isForceDisabled()) {
            $this->dispatch('error', 'Server is not available.');

            return;
        }

        $this->connected = true;
        $this->currentPath = '/';
        $this->listFiles();
    }

    public function updatedSelectedContainer()
    {
        if (! empty($this->selectedContainer)) {
            $this->connect();
        }
    }

    private function getServer(): ?Server
    {
        $container = $this->containers->firstWhere('container.Names', $this->selectedContainer);

        return data_get($container, 'server');
    }

    private function sanitizePath(string $path): string
    {
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

    public function listFiles()
    {
        $server = $this->getServer();
        if (! $server) {
            return;
        }

        $path = $this->sanitizePath($this->currentPath);
        $this->currentPath = $path;
        $escapedContainer = escapeshellarg($this->selectedContainer);
        $escapedPath = escapeshellarg($path);

        try {
            $command = "docker exec {$escapedContainer} ls -la --time-style=long-iso {$escapedPath} 2>/dev/null || docker exec {$escapedContainer} ls -la {$escapedPath}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $output = instant_remote_process([$command], $server, throwError: false);

            $this->files = [];
            if ($output) {
                $lines = explode("\n", trim($output));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || str_starts_with($line, 'total')) {
                        continue;
                    }

                    $parsed = $this->parseLsLine($line);
                    if ($parsed && $parsed['name'] !== '.') {
                        $this->files[] = $parsed;
                    }
                }
            }

            $this->sortFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to list files: '.$e->getMessage());
        }
    }

    private function parseLsLine(string $line): ?array
    {
        $parts = preg_split('/\s+/', $line, 9);
        if (count($parts) < 9) {
            $parts = preg_split('/\s+/', $line, 8);
            if (count($parts) < 8) {
                return null;
            }
            $permissions = $parts[0];
            $owner = $parts[2] ?? '-';
            $group = $parts[3] ?? '-';
            $size = $parts[4] ?? '0';
            $name = end($parts);
        } else {
            $permissions = $parts[0];
            $owner = $parts[2];
            $group = $parts[3];
            $size = $parts[4];
            $name = $parts[8];
        }

        if (empty($name)) {
            return null;
        }

        $isDirectory = str_starts_with($permissions, 'd');
        $isLink = str_starts_with($permissions, 'l');

        $displayName = $name;
        $linkTarget = null;
        if ($isLink && str_contains($name, ' -> ')) {
            [$displayName, $linkTarget] = explode(' -> ', $name, 2);
        }

        return [
            'name' => $displayName,
            'permissions' => $permissions,
            'owner' => $owner,
            'group' => $group,
            'size' => $this->formatSize((int) $size),
            'rawSize' => (int) $size,
            'isDirectory' => $isDirectory,
            'isLink' => $isLink,
            'linkTarget' => $linkTarget,
        ];
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 1).' '.$units[$i];
    }

    public function navigateTo(string $name)
    {
        if ($name === '..') {
            $this->currentPath = dirname($this->currentPath);
            if ($this->currentPath === '.') {
                $this->currentPath = '/';
            }
        } else {
            $this->currentPath = rtrim($this->currentPath, '/').'/'.$name;
        }
        $this->currentPath = $this->sanitizePath($this->currentPath);
        $this->listFiles();
    }

    public function navigateToPath(string $path)
    {
        $this->currentPath = $this->sanitizePath($path);
        $this->listFiles();
    }

    public function downloadFile(string $filename)
    {
        $server = $this->getServer();
        if (! $server) {
            return;
        }

        if (! preg_match('/^[^\/\0]+$/', $filename)) {
            $this->dispatch('error', 'Invalid filename.');

            return;
        }

        $filePath = rtrim($this->currentPath, '/').'/'.$filename;
        $filePath = $this->sanitizePath($filePath);
        $escapedContainer = escapeshellarg($this->selectedContainer);
        $escapedPath = escapeshellarg($filePath);

        try {
            $tmpFile = '/tmp/coolify-download-'.md5($filePath.time());
            $escapedTmp = escapeshellarg($tmpFile);

            $commands = [
                "docker cp {$escapedContainer}:{$escapedPath} {$escapedTmp}",
                "base64 {$escapedTmp}",
                "rm -f {$escapedTmp}",
            ];

            if ($server->isNonRoot()) {
                $commands = array_map(fn ($cmd) => "sudo {$cmd}", $commands);
            }

            $output = instant_remote_process($commands, $server);

            if ($output) {
                $content = base64_decode($output);

                return response()->streamDownload(function () use ($content) {
                    echo $content;
                }, $filename);
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Download failed: '.$e->getMessage());
        }
    }

    public function uploadToContainer()
    {
        $server = $this->getServer();
        if (! $server) {
            return;
        }

        if (! $this->uploadFile) {
            $this->dispatch('error', 'No file selected.');

            return;
        }

        $this->validate([
            'uploadFile' => 'required|file|max:102400',
        ]);

        $escapedContainer = escapeshellarg($this->selectedContainer);
        $originalName = $this->uploadFile->getClientOriginalName();

        if (! preg_match('/^[a-zA-Z0-9._\-\s()]+$/', $originalName)) {
            $this->dispatch('error', 'Invalid filename. Use only alphanumeric characters, dots, dashes, underscores, and spaces.');

            return;
        }

        $targetPath = rtrim($this->currentPath, '/').'/'.$originalName;
        $targetPath = $this->sanitizePath($targetPath);
        $escapedTarget = escapeshellarg($targetPath);

        try {
            $content = base64_encode(file_get_contents($this->uploadFile->getRealPath()));
            $tmpFile = '/tmp/coolify-upload-'.md5($targetPath.time());
            $escapedTmp = escapeshellarg($tmpFile);

            $commands = [
                "echo '{$content}' | base64 -d > {$escapedTmp}",
                "docker cp {$escapedTmp} {$escapedContainer}:{$escapedTarget}",
                "rm -f {$escapedTmp}",
            ];

            if ($server->isNonRoot()) {
                $commands = array_map(fn ($cmd) => "sudo {$cmd}", $commands);
            }

            instant_remote_process($commands, $server);

            $this->uploadFile = null;
            $this->dispatch('success', "File '{$originalName}' uploaded successfully.");
            $this->listFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Upload failed: '.$e->getMessage());
        }
    }

    public function createFolder()
    {
        $server = $this->getServer();
        if (! $server) {
            return;
        }

        if (empty($this->newFolderName)) {
            $this->dispatch('error', 'Please enter a folder name.');

            return;
        }

        if (! preg_match('/^[a-zA-Z0-9._\-\s]+$/', $this->newFolderName)) {
            $this->dispatch('error', 'Invalid folder name. Use only alphanumeric characters, dots, dashes, underscores, and spaces.');

            return;
        }

        $escapedContainer = escapeshellarg($this->selectedContainer);
        $folderPath = rtrim($this->currentPath, '/').'/'.$this->newFolderName;
        $folderPath = $this->sanitizePath($folderPath);
        $escapedPath = escapeshellarg($folderPath);

        try {
            $command = "docker exec {$escapedContainer} mkdir -p {$escapedPath}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            instant_remote_process([$command], $server);
            $this->newFolderName = '';
            $this->dispatch('success', 'Folder created successfully.');
            $this->listFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create folder: '.$e->getMessage());
        }
    }

    public function deleteItem(string $name, bool $isDirectory = false)
    {
        $server = $this->getServer();
        if (! $server) {
            return;
        }

        if (! preg_match('/^[^\/\0]+$/', $name)) {
            $this->dispatch('error', 'Invalid name.');

            return;
        }

        $escapedContainer = escapeshellarg($this->selectedContainer);
        $itemPath = rtrim($this->currentPath, '/').'/'.$name;
        $itemPath = $this->sanitizePath($itemPath);
        $escapedPath = escapeshellarg($itemPath);

        try {
            $flag = $isDirectory ? '-rf' : '';
            $command = "docker exec {$escapedContainer} rm {$flag} {$escapedPath}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            instant_remote_process([$command], $server);
            $this->dispatch('success', "'{$name}' deleted successfully.");
            $this->listFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete: '.$e->getMessage());
        }
    }

    public function sort(string $column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
        $this->sortFiles();
    }

    private function sortFiles()
    {
        usort($this->files, function ($a, $b) {
            if ($a['name'] === '..') {
                return -1;
            }
            if ($b['name'] === '..') {
                return 1;
            }

            if ($a['isDirectory'] !== $b['isDirectory']) {
                return $a['isDirectory'] ? -1 : 1;
            }

            $result = match ($this->sortBy) {
                'size' => $a['rawSize'] <=> $b['rawSize'],
                'permissions' => strcmp($a['permissions'], $b['permissions']),
                default => strcasecmp($a['name'], $b['name']),
            };

            return $this->sortDirection === 'asc' ? $result : -$result;
        });
    }

    public function getBreadcrumbs(): array
    {
        $parts = array_filter(explode('/', $this->currentPath));
        $breadcrumbs = [['name' => '/', 'path' => '/']];
        $current = '';
        foreach ($parts as $part) {
            $current .= '/'.$part;
            $breadcrumbs[] = ['name' => $part, 'path' => $current];
        }

        return $breadcrumbs;
    }

    public function render()
    {
        return view('livewire.project.shared.file-browser');
    }
}
