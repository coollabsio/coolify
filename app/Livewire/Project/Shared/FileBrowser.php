<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class FileBrowser extends Component
{
    use WithFileUploads;

    public string $currentPath = '/';

    public array $entries = [];

    public string $type = '';

    public $resource;

    public array $parameters = [];

    public Collection $containers;

    public Collection $servers;

    public string $selectedContainer = 'default';

    public bool $isLoading = false;

    public string $newFolderName = '';

    public bool $showNewFolderModal = false;

    public $uploadFile;

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    public string $errorMessage = '';

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
    }

    public function loadContainers(): void
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

        $this->containers = $this->containers->sortBy(fn ($c) => data_get($c, 'container.Names'));

        if ($this->containers->count() === 1) {
            $this->selectedContainer = data_get($this->containers->first(), 'container.Names');
            $this->listDirectory();
        }
    }

    public function updatedSelectedContainer(): void
    {
        if ($this->selectedContainer !== 'default') {
            $this->currentPath = '/';
            $this->listDirectory();
        }
    }

    private function getServer(): ?Server
    {
        $container = $this->containers->firstWhere('container.Names', $this->selectedContainer);

        return data_get($container, 'server');
    }

    private function validateContainerName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) === 1;
    }

    private function validatePath(string $path): bool
    {
        if (! str_starts_with($path, '/')) {
            return false;
        }

        $dangerous = ['$(', '`', '|', ';', '&', '>', '<', "\n", "\r", "\0"];
        foreach ($dangerous as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        return true;
    }

    private function normalizePath(string $path): string
    {
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

    public function listDirectory(?string $path = null): void
    {
        if ($this->selectedContainer === 'default') {
            $this->dispatch('error', 'Please select a container.');

            return;
        }

        if (! $this->validateContainerName($this->selectedContainer)) {
            $this->dispatch('error', 'Invalid container name.');

            return;
        }

        $server = $this->getServer();
        if (! $server) {
            $this->dispatch('error', 'Server not found.');

            return;
        }

        $targetPath = $path ?? $this->currentPath;
        $targetPath = $this->normalizePath($targetPath);

        if (! $this->validatePath($targetPath)) {
            $this->dispatch('error', 'Invalid path.');

            return;
        }

        $this->isLoading = true;
        $this->errorMessage = '';

        try {
            $escapedContainer = escapeshellarg($this->selectedContainer);
            $escapedPath = escapeshellarg($targetPath);

            $command = "docker exec {$escapedContainer} sh -c ".escapeshellarg(
                "ls -la {$targetPath} 2>&1"
            );

            $output = instant_remote_process([$command], $server, throwError: false);

            if (is_null($output) || str_contains($output, 'No such file or directory')) {
                $this->errorMessage = 'Directory not found: '.$targetPath;
                $this->isLoading = false;

                return;
            }

            if (str_contains($output, 'Permission denied')) {
                $this->errorMessage = 'Permission denied: '.$targetPath;
                $this->isLoading = false;

                return;
            }

            $this->currentPath = $targetPath;
            $this->entries = $this->parseLsOutput($output);
            $this->sortEntries();
        } catch (\Throwable $e) {
            $this->errorMessage = 'Error listing directory: '.$e->getMessage();
        } finally {
            $this->isLoading = false;
        }
    }

    private function parseLsOutput(string $output): array
    {
        $entries = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || str_starts_with($line, 'total ')) {
                continue;
            }

            if (! preg_match('/^([dlcbsp\-])([rwxsStT\-]{9})\s+(\d+)\s+(\S+)\s+(\S+)\s+(\d+(?:,\s*\d+)?)\s+(\w+\s+\d+\s+[\d:]+)\s+(.+)$/', $line, $matches)) {
                continue;
            }

            $typeChar = $matches[1];
            $permissions = $matches[1].$matches[2];
            $owner = $matches[4];
            $group = $matches[5];
            $size = $matches[6];
            $modified = $matches[7];
            $name = $matches[8];

            $linkTarget = null;
            if ($typeChar === 'l' && str_contains($name, ' -> ')) {
                [$name, $linkTarget] = explode(' -> ', $name, 2);
            }

            if ($name === '.' || $name === '..') {
                continue;
            }

            $type = match ($typeChar) {
                'd' => 'directory',
                'l' => 'symlink',
                '-' => 'file',
                default => 'other',
            };

            $entries[] = [
                'name' => $name,
                'type' => $type,
                'permissions' => $permissions,
                'owner' => $owner,
                'group' => $group,
                'size' => (int) str_replace(',', '', $size),
                'sizeFormatted' => $this->formatSize((int) str_replace(',', '', $size)),
                'modified' => $modified,
                'linkTarget' => $linkTarget,
                'isDirectory' => $type === 'directory' || ($type === 'symlink' && $linkTarget !== null),
            ];
        }

        return $entries;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 1).' '.$units[$i];
    }

    public function navigateTo(string $name, string $type): void
    {
        if ($type === 'directory' || $type === 'symlink') {
            $newPath = rtrim($this->currentPath, '/').'/'.$name;
            $this->listDirectory($newPath);
        }
    }

    public function navigateUp(): void
    {
        if ($this->currentPath === '/') {
            return;
        }

        $parentPath = dirname($this->currentPath);
        $this->listDirectory($parentPath);
    }

    public function navigateToPath(string $path): void
    {
        $this->listDirectory($path);
    }

    public function getBreadcrumbs(): array
    {
        if ($this->currentPath === '/') {
            return [['name' => '/', 'path' => '/']];
        }

        $parts = array_filter(explode('/', $this->currentPath));
        $breadcrumbs = [['name' => '/', 'path' => '/']];
        $currentPath = '';

        foreach ($parts as $part) {
            $currentPath .= '/'.$part;
            $breadcrumbs[] = ['name' => $part, 'path' => $currentPath];
        }

        return $breadcrumbs;
    }

    public function downloadFile(string $name): void
    {
        if ($this->selectedContainer === 'default') {
            $this->dispatch('error', 'Please select a container.');

            return;
        }

        $server = $this->getServer();
        if (! $server) {
            $this->dispatch('error', 'Server not found.');

            return;
        }

        $filePath = rtrim($this->currentPath, '/').'/'.$name;
        $filePath = $this->normalizePath($filePath);

        if (! $this->validatePath($filePath)) {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        try {
            $escapedContainer = escapeshellarg($this->selectedContainer);
            $escapedPath = escapeshellarg($filePath);

            // Check file size first (limit to 50MB)
            $sizeOutput = instant_remote_process(
                ["docker exec {$escapedContainer} stat -c %s {$escapedPath} 2>/dev/null || docker exec {$escapedContainer} stat -f %z {$escapedPath} 2>/dev/null"],
                $server,
                throwError: false
            );

            $fileSize = (int) trim($sizeOutput ?? '0');
            if ($fileSize > 50 * 1024 * 1024) {
                $this->dispatch('error', 'File is too large to download (max 50MB). Size: '.$this->formatSize($fileSize));

                return;
            }

            // Read file content as base64
            $base64Content = instant_remote_process(
                ["docker exec {$escapedContainer} base64 {$escapedPath}"],
                $server,
                throwError: true
            );

            if (is_null($base64Content)) {
                $this->dispatch('error', 'Failed to read file.');

                return;
            }

            $content = base64_decode($base64Content);

            // Store temporarily and trigger download
            $tempPath = 'file-browser/'.auth()->id().'/'.$name;
            Storage::put($tempPath, $content);

            $this->dispatch('triggerDownload', name: $name, path: $tempPath);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error downloading file: '.$e->getMessage());
        }
    }

    public function updatedUploadFile(): void
    {
        $this->uploadFileToContainer();
    }

    public function uploadFileToContainer(): void
    {
        if ($this->selectedContainer === 'default') {
            $this->dispatch('error', 'Please select a container.');

            return;
        }

        if (! $this->uploadFile) {
            $this->dispatch('error', 'No file selected.');

            return;
        }

        $server = $this->getServer();
        if (! $server) {
            $this->dispatch('error', 'Server not found.');

            return;
        }

        try {
            $fileName = $this->uploadFile->getClientOriginalName();

            if (! preg_match('/^[a-zA-Z0-9._\-\s]+$/', $fileName)) {
                $this->dispatch('error', 'Invalid filename. Only alphanumeric characters, dots, dashes, underscores, and spaces are allowed.');

                return;
            }

            $targetPath = rtrim($this->currentPath, '/').'/'.$fileName;
            $targetPath = $this->normalizePath($targetPath);
            $tempLocalPath = $this->uploadFile->getRealPath();

            // SCP file to server temp location
            $serverTempPath = '/tmp/coolify-upload-'.uniqid();
            instant_scp($tempLocalPath, $serverTempPath, $server);

            // Copy from server to container
            $escapedContainer = escapeshellarg($this->selectedContainer);
            $escapedTarget = escapeshellarg($targetPath);
            instant_remote_process(
                ["docker cp {$serverTempPath} {$escapedContainer}:{$escapedTarget}"],
                $server
            );

            // Cleanup server temp file
            instant_remote_process(["rm -f {$serverTempPath}"], $server, throwError: false);

            $this->uploadFile = null;
            $this->dispatch('success', "File '{$fileName}' uploaded successfully.");
            $this->listDirectory();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error uploading file: '.$e->getMessage());
        }
    }

    public function createFolder(): void
    {
        if ($this->selectedContainer === 'default') {
            $this->dispatch('error', 'Please select a container.');

            return;
        }

        if (empty(trim($this->newFolderName))) {
            $this->dispatch('error', 'Folder name cannot be empty.');

            return;
        }

        if (! preg_match('/^[a-zA-Z0-9._\-]+$/', $this->newFolderName)) {
            $this->dispatch('error', 'Invalid folder name. Only alphanumeric characters, dots, dashes, and underscores are allowed.');

            return;
        }

        $server = $this->getServer();
        if (! $server) {
            $this->dispatch('error', 'Server not found.');

            return;
        }

        try {
            $folderPath = rtrim($this->currentPath, '/').'/'.$this->newFolderName;
            $folderPath = $this->normalizePath($folderPath);

            $escapedContainer = escapeshellarg($this->selectedContainer);
            $escapedPath = escapeshellarg($folderPath);

            instant_remote_process(
                ["docker exec {$escapedContainer} mkdir -p {$escapedPath}"],
                $server
            );

            $this->newFolderName = '';
            $this->showNewFolderModal = false;
            $this->dispatch('success', 'Folder created successfully.');
            $this->listDirectory();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error creating folder: '.$e->getMessage());
        }
    }

    public function deleteEntry(string $name, string $type): void
    {
        if ($this->selectedContainer === 'default') {
            $this->dispatch('error', 'Please select a container.');

            return;
        }

        $server = $this->getServer();
        if (! $server) {
            $this->dispatch('error', 'Server not found.');

            return;
        }

        if (! preg_match('/^[a-zA-Z0-9._\-\s]+$/', $name)) {
            $this->dispatch('error', 'Invalid entry name.');

            return;
        }

        try {
            $entryPath = rtrim($this->currentPath, '/').'/'.$name;
            $entryPath = $this->normalizePath($entryPath);

            $escapedContainer = escapeshellarg($this->selectedContainer);
            $escapedPath = escapeshellarg($entryPath);

            $flag = ($type === 'directory') ? '-rf' : '-f';

            instant_remote_process(
                ["docker exec {$escapedContainer} rm {$flag} {$escapedPath}"],
                $server
            );

            $this->dispatch('success', "'{$name}' deleted successfully.");
            $this->listDirectory();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error deleting: '.$e->getMessage());
        }
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->sortEntries();
    }

    private function sortEntries(): void
    {
        $entries = collect($this->entries);

        $directories = $entries->where('type', 'directory');
        $files = $entries->where('type', '!=', 'directory');

        $sortFn = function ($a, $b) {
            $column = $this->sortBy;
            $aVal = $a[$column] ?? '';
            $bVal = $b[$column] ?? '';

            if ($column === 'size') {
                $result = $aVal <=> $bVal;
            } else {
                $result = strcasecmp((string) $aVal, (string) $bVal);
            }

            return $this->sortDirection === 'asc' ? $result : -$result;
        };

        $directories = $directories->sort($sortFn)->values();
        $files = $files->sort($sortFn)->values();

        $this->entries = $directories->merge($files)->toArray();
    }

    public function refreshDirectory(): void
    {
        $this->listDirectory();
    }

    public function render()
    {
        return view('livewire.project.shared.file-browser', [
            'breadcrumbs' => $this->getBreadcrumbs(),
        ]);
    }
}
