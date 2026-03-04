<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class FileExplorer extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public $selected_container = 'default';

    public Collection $containers;

    public $parameters;

    public $resource;

    public string $type;

    public Collection $servers;

    public string $currentPath = '/';

    public array $files = [];

    public bool $isLoading = false;

    public ?string $selectedFile = null;

    public ?string $fileContent = null;

    public bool $isEditing = false;

    public ?string $newFolderName = null;

    public bool $showCreateFolder = false;

    public ?string $uploadFile = null;

    public ?string $moveSource = null;

    public ?string $moveDestination = null;

    public bool $showMoveDialog = false;

    public array $selectedFiles = [];

    public bool $showCompressDialog = false;

    public ?string $compressArchiveName = null;

    public bool $overwriteExisting = false;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->containers = collect();
        $this->servers = collect();
        $this->files = [];

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

        $this->servers = $this->servers->sortByDesc(fn ($server) => $server->isTerminalEnabled());

        if ($this->containers->count() === 1) {
            $this->selected_container = data_get($this->containers->first(), 'container.Names');
            $this->loadFiles();
        }
    }

    public function loadContainers()
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
                    if (data_get($container, 'State') === 'running' && $server->isTerminalEnabled()) {
                        $payload = [
                            'server' => $server,
                            'container' => $container,
                        ];
                        $this->containers = $this->containers->push($payload);
                    }
                }
            } elseif (data_get($this->parameters, 'database_uuid')) {
                if ($this->resource->isRunning() && $server->isTerminalEnabled()) {
                    $this->containers = $this->containers->push([
                        'server' => $server,
                        'container' => [
                            'Names' => $this->resource->uuid,
                        ],
                    ]);
                }
            } elseif (data_get($this->parameters, 'service_uuid')) {
                $this->resource->applications()->get()->each(function ($application) {
                    if ($application->isRunning() && $this->resource->server->isTerminalEnabled()) {
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

        $this->containers = $this->containers->sortBy(function ($container) {
            return data_get($container, 'container.Names');
        });
    }

    public function updatedSelectedContainer()
    {
        if ($this->selected_container !== 'default') {
            $this->currentPath = '/';
            $this->loadFiles();
        }
    }

    public function loadFiles()
    {
        if ($this->selected_container === 'default') {
            $this->files = [];

            return;
        }

        $this->isLoading = true;

        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            if (! $server || ! $server instanceof Server) {
                $this->dispatch('error', 'Invalid server configuration.');

                return;
            }

            if ($server->isForceDisabled()) {
                $this->dispatch('error', 'Server is disabled.');

                return;
            }

            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $escapedPath = escapeshellarg($this->currentPath);

            // List files and directories with detailed info
            $command = "docker exec {$escapedContainer} sh -c 'ls -lah {$escapedPath} 2>/dev/null | tail -n +2'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $output = instant_remote_process([$command], $server, false);

            $this->files = $this->parseFileList($output ?? '');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load files: '.$e->getMessage());
            $this->files = [];
        } finally {
            $this->isLoading = false;
        }
    }

    private function parseFileList(string $output): array
    {
        $files = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            // Parse ls -lah output format: permissions links owner group size date time name
            // Example: -rw-r--r-- 1 root root 1234 Dec 25 10:30 filename.txt
            // Or: drwxr-xr-x 2 root root 4096 Dec 25 10:30 directory
            if (preg_match('/^([d\-])([rwx\-]+)\s+\d+\s+\S+\s+\S+\s+(\S+)\s+(\S+\s+\d+\s+[\d:]+)\s+(.+)$/', $line, $matches)) {
                $isDirectory = $matches[1] === 'd';
                $permissions = $matches[1].$matches[2];
                $size = $matches[3];
                $date = $matches[4];
                $name = trim($matches[5]);

                // Skip . and .. entries
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $fullPath = rtrim($this->currentPath, '/').'/'.$name;
                if ($this->currentPath === '/') {
                    $fullPath = '/'.$name;
                }

                $files[] = [
                    'name' => $name,
                    'path' => $fullPath,
                    'is_directory' => $isDirectory,
                    'size' => $isDirectory ? '-' : $this->formatSize((int) $size),
                    'permissions' => $permissions,
                    'date' => $date,
                ];
            }
        }

        // Sort: directories first, then files, both alphabetically
        usort($files, function ($a, $b) {
            if ($a['is_directory'] && ! $b['is_directory']) {
                return -1;
            }
            if (! $a['is_directory'] && $b['is_directory']) {
                return 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $files;
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public function navigateTo(string $path)
    {
        $this->currentPath = $path;
        $this->selectedFile = null;
        $this->fileContent = null;
        $this->isEditing = false;
        $this->selectedFiles = [];
        $this->loadFiles();
    }

    public function openFile(string $path)
    {
        $file = collect($this->files)->firstWhere('path', $path);
        if (! $file || $file['is_directory']) {
            return;
        }

        // Check if it's a text file
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $textExtensions = ['txt', 'php', 'env', 'js', 'json', 'css', 'html', 'xml', 'yaml', 'yml', 'md', 'log', 'conf', 'ini', 'sh', 'bash', 'py', 'rb', 'java', 'cpp', 'c', 'h', 'sql', 'vue', 'ts', 'tsx', 'jsx'];

        if (! in_array($extension, $textExtensions)) {
            $this->dispatch('error', 'This file type cannot be edited. Only text files are supported.');

            return;
        }

        $this->selectedFile = $path;
        $this->isEditing = false;
        $this->loadFileContent($path);
    }

    public function loadFileContent(string $path)
    {
        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $escapedPath = escapeshellarg($path);

            $command = "docker exec {$escapedContainer} sh -c 'cat {$escapedPath}'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $this->fileContent = instant_remote_process([$command], $server, false) ?? '';
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load file: '.$e->getMessage());
            $this->fileContent = '';
        }
    }

    public function saveFile()
    {
        if (! $this->selectedFile || ! $this->isEditing) {
            return;
        }

        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $escapedPath = escapeshellarg($this->selectedFile);

            // Save content to a temporary file locally
            $tmpFilename = 'temp/'.uniqid().'.txt';
            Storage::disk('local')->put($tmpFilename, $this->fileContent);
            $localTmpPath = Storage::disk('local')->path($tmpFilename);

            // Copy to server temp location
            $serverTmpPath = '/tmp/'.basename($tmpFilename);
            instant_scp($localTmpPath, $serverTmpPath, $server);

            // Copy from server temp to container
            $command = "docker cp {$serverTmpPath} {$escapedContainer}:{$escapedPath}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            instant_remote_process([$command], $server);

            // Clean up temp files
            Storage::disk('local')->delete($tmpFilename);
            $command = "rm -f {$serverTmpPath}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            instant_remote_process([$command], $server, false);

            $this->isEditing = false;
            $this->dispatch('success', 'File saved successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to save file: '.$e->getMessage());
        }
    }

    public function createFolder()
    {
        if (empty($this->newFolderName)) {
            $this->dispatch('error', 'Folder name is required.');

            return;
        }

        // Validate folder name
        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $this->newFolderName)) {
            $this->dispatch('error', 'Invalid folder name. Only alphanumeric characters, dots, dashes, and underscores are allowed.');

            return;
        }

        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $newPath = rtrim($this->currentPath, '/').'/'.$this->newFolderName;
            if ($this->currentPath === '/') {
                $newPath = '/'.$this->newFolderName;
            }
            $escapedPath = escapeshellarg($newPath);

            $command = "docker exec {$escapedContainer} sh -c 'mkdir -p {$escapedPath}'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            instant_remote_process([$command], $server);

            $this->newFolderName = null;
            $this->showCreateFolder = false;
            $this->dispatch('success', 'Folder created successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create folder: '.$e->getMessage());
        }
    }

    public function updatedUploadFile()
    {
        $this->validate([
            'uploadFile' => 'required|file|max:102400', // Max 100MB
        ]);

        if (! $this->uploadFile) {
            $this->dispatch('error', 'Please select a file to upload.');

            return;
        }

        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);

            // Save uploaded file temporarily
            $filename = $this->uploadFile->getClientOriginalName();
            $tmpPath = $this->uploadFile->storeAs('temp', uniqid().'_'.$filename, 'local');
            $fullTmpPath = Storage::disk('local')->path($tmpPath);

            // Copy to server
            $serverTmpPath = '/tmp/'.basename($tmpPath);
            instant_scp($fullTmpPath, $serverTmpPath, $server);

            // Copy from server to container
            $destinationPath = rtrim($this->currentPath, '/').'/'.$filename;
            if ($this->currentPath === '/') {
                $destinationPath = '/'.$filename;
            }
            $escapedDest = escapeshellarg($destinationPath);

            $command = "docker cp {$serverTmpPath} {$escapedContainer}:{$escapedDest}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            instant_remote_process([$command], $server);

            // Clean up
            Storage::disk('local')->delete($tmpPath);
            $command = "rm -f {$serverTmpPath}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            instant_remote_process([$command], $server, false);

            $this->uploadFile = null;
            $this->dispatch('success', 'File uploaded successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to upload file: '.$e->getMessage());
            $this->uploadFile = null;
        }
    }

    public function deleteFile(string $path)
    {
        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $escapedPath = escapeshellarg($path);

            $command = "docker exec {$escapedContainer} sh -c 'rm -rf {$escapedPath}'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            instant_remote_process([$command], $server);

            if ($this->selectedFile === $path) {
                $this->selectedFile = null;
                $this->fileContent = null;
                $this->isEditing = false;
            }

            $this->dispatch('success', 'File deleted successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete file: '.$e->getMessage());
        }
    }

    public function toggleFileSelection(string $path)
    {
        if (in_array($path, $this->selectedFiles)) {
            $this->selectedFiles = array_values(array_diff($this->selectedFiles, [$path]));
        } else {
            $this->selectedFiles[] = $path;
        }
    }

    public function selectAll()
    {
        $allSelected = count($this->selectedFiles) === count($this->files);
        if ($allSelected) {
            $this->selectedFiles = [];
        } else {
            $this->selectedFiles = collect($this->files)->pluck('path')->toArray();
        }
    }

    public function deselectAll()
    {
        $this->selectedFiles = [];
    }

    public function openCompressDialog()
    {
        if (empty($this->selectedFiles)) {
            $this->dispatch('error', 'Please select at least one file or folder to compress.');

            return;
        }

        $this->compressArchiveName = 'archive_'.date('Y-m-d_His').'.zip';
        $this->showCompressDialog = true;
    }

    public function compressSelectedFiles()
    {
        if (empty($this->selectedFiles)) {
            $this->dispatch('error', 'Please select at least one file or folder to compress.');
            $this->showCompressDialog = false;

            return;
        }

        if (empty($this->compressArchiveName)) {
            $this->dispatch('error', 'Archive name is required.');
            $this->showCompressDialog = false;

            return;
        }

        // Validate archive name
        if (! preg_match('/^[a-zA-Z0-9._-]+\.(zip|tar|tar\.gz|tar\.bz2|tar\.xz|tgz|tbz2|tbz|txz)$/', $this->compressArchiveName)) {
            $this->dispatch('error', 'Invalid archive name. Use .zip, .tar, .tar.gz, .tar.bz2, or .tar.xz extension.');

            return;
        }

        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');
                $this->showCompressDialog = false;

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $dirPath = $this->currentPath;
            $escapedDir = escapeshellarg($dirPath);

            // Check if archive already exists
            $archivePath = rtrim($dirPath, '/').'/'.$this->compressArchiveName;
            if ($dirPath === '/') {
                $archivePath = '/'.$this->compressArchiveName;
            }
            $escapedArchive = escapeshellarg($archivePath);

            // Check if file exists
            $checkCommand = "docker exec {$escapedContainer} sh -c 'test -f {$escapedArchive} && echo exists || echo not_exists'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $exists = trim(instant_remote_process([$checkCommand], $server, false) ?? '') === 'exists';

            if ($exists && ! $this->overwriteExisting) {
                $this->dispatch('error', 'Archive already exists. Enable "Overwrite existing" to replace it.');

                return;
            }

            // Prepare file list for compression
            $fileNames = [];
            foreach ($this->selectedFiles as $selectedPath) {
                $file = collect($this->files)->firstWhere('path', $selectedPath);
                if ($file) {
                    $fileNames[] = escapeshellarg(basename($selectedPath));
                }
            }

            if (empty($fileNames)) {
                $this->dispatch('error', 'No valid files selected.');
                $this->showCompressDialog = false;

                return;
            }

            $filesList = implode(' ', $fileNames);

            // Determine compression format
            $extension = strtolower(pathinfo($this->compressArchiveName, PATHINFO_EXTENSION));
            $baseExtension = strtolower(pathinfo(pathinfo($this->compressArchiveName, PATHINFO_FILENAME), PATHINFO_EXTENSION));

            $command = "docker exec {$escapedContainer} sh -c 'cd {$escapedDir} && ";

            if ($extension === 'zip') {
                $command .= 'if command -v zip >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $command .= "rm -f {$escapedArchive} && ";
                }
                $command .= "zip -r {$escapedArchive} {$filesList} 2>&1; ";
                $command .= 'else echo "zip not available" && exit 1; ';
                $command .= 'fi';
            } elseif (in_array($extension, ['gz', 'tgz']) || ($extension === 'gz' && str_ends_with($baseExtension, '.tar'))) {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v gzip >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $command .= "rm -f {$escapedArchive} && ";
                }
                $command .= "tar -czf {$escapedArchive} {$filesList} 2>&1; ";
                $command .= 'else echo "tar/gzip not available" && exit 1; ';
                $command .= 'fi';
            } elseif (in_array($extension, ['bz2', 'tbz2', 'tbz']) || ($extension === 'bz2' && str_ends_with($baseExtension, '.tar'))) {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v bzip2 >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $command .= "rm -f {$escapedArchive} && ";
                }
                $command .= "tar -cjf {$escapedArchive} {$filesList} 2>&1; ";
                $command .= 'else echo "tar/bzip2 not available" && exit 1; ';
                $command .= 'fi';
            } elseif (in_array($extension, ['xz', 'txz']) || ($extension === 'xz' && str_ends_with($baseExtension, '.tar'))) {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v xz >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $command .= "rm -f {$escapedArchive} && ";
                }
                $command .= "tar -cJf {$escapedArchive} {$filesList} 2>&1; ";
                $command .= 'else echo "tar/xz not available" && exit 1; ';
                $command .= 'fi';
            } elseif ($extension === 'tar') {
                $command .= 'if command -v tar >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $command .= "rm -f {$escapedArchive} && ";
                }
                $command .= "tar -cf {$escapedArchive} {$filesList} 2>&1; ";
                $command .= 'else echo "tar not available" && exit 1; ';
                $command .= 'fi';
            } else {
                $this->dispatch('error', 'Unsupported archive format. Use .zip, .tar, .tar.gz, .tar.bz2, or .tar.xz');
                $this->showCompressDialog = false;

                return;
            }

            $command .= "'";

            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $output = instant_remote_process([$command], $server, false);
            if (str_contains($output ?? '', 'not available')) {
                $this->dispatch('error', 'Required compression tool not available in container.');
                $this->showCompressDialog = false;

                return;
            }

            $this->selectedFiles = [];
            $this->compressArchiveName = null;
            $this->overwriteExisting = false;
            $this->showCompressDialog = false;
            $this->dispatch('success', 'Files compressed successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to compress files: '.$e->getMessage());
            $this->showCompressDialog = false;
        }
    }

    public function compressFile(string $path)
    {
        $this->selectedFiles = [$path];
        $this->openCompressDialog();
    }

    public function decompressFile(string $path)
    {
        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $escapedPath = escapeshellarg($path);
            $dirPath = dirname($path);
            $escapedDir = escapeshellarg($dirPath);
            $fileName = basename($path);
            $escapedFileName = escapeshellarg($fileName);

            // Detect archive format and decompress accordingly
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $baseExtension = strtolower(pathinfo($path, PATHINFO_FILENAME));
            $fullExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            // Handle multi-extension files like .tar.gz, .tar.bz2, .tar.xz
            if (str_ends_with($baseExtension, '.tar')) {
                $fullExtension = 'tar.'.str_replace('.tar', '', $baseExtension);
            }

            $command = "docker exec {$escapedContainer} sh -c 'cd {$escapedDir} && ";

            // ZIP files
            if ($extension === 'zip') {
                $command .= 'if command -v unzip >/dev/null 2>&1; then ';
                $command .= "unzip -o {$escapedPath}; ";
                $command .= 'else echo "unzip not available" && exit 1; ';
                $command .= 'fi';
            }
            // TAR.GZ or TGZ files
            elseif ($extension === 'gz' && str_ends_with($baseExtension, '.tar')) {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v gzip >/dev/null 2>&1; then ';
                $command .= "tar -xzf {$escapedPath}; ";
                $command .= 'else echo "tar/gzip not available" && exit 1; ';
                $command .= 'fi';
            }
            // TAR.BZ2 or TBZ2 files
            elseif ($extension === 'bz2' && str_ends_with($baseExtension, '.tar')) {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v bzip2 >/dev/null 2>&1; then ';
                $command .= "tar -xjf {$escapedPath}; ";
                $command .= 'else echo "tar/bzip2 not available" && exit 1; ';
                $command .= 'fi';
            }
            // TAR.XZ or TXZ files
            elseif ($extension === 'xz' && str_ends_with($baseExtension, '.tar')) {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v xz >/dev/null 2>&1; then ';
                $command .= "tar -xJf {$escapedPath}; ";
                $command .= 'else echo "tar/xz not available" && exit 1; ';
                $command .= 'fi';
            }
            // Plain TAR files
            elseif ($extension === 'tar') {
                $command .= 'if command -v tar >/dev/null 2>&1; then ';
                $command .= "tar -xf {$escapedPath}; ";
                $command .= 'else echo "tar not available" && exit 1; ';
                $command .= 'fi';
            }
            // GZ files (gzip only, not tar)
            elseif ($extension === 'gz') {
                $command .= 'if command -v gunzip >/dev/null 2>&1; then ';
                $command .= "gunzip -f {$escapedPath}; ";
                $command .= 'elif command -v gzip >/dev/null 2>&1; then ';
                $command .= "gzip -df {$escapedPath}; ";
                $command .= 'else echo "gzip not available" && exit 1; ';
                $command .= 'fi';
            }
            // BZ2 files (bzip2 only, not tar)
            elseif ($extension === 'bz2') {
                $command .= 'if command -v bunzip2 >/dev/null 2>&1; then ';
                $command .= "bunzip2 -f {$escapedPath}; ";
                $command .= 'elif command -v bzip2 >/dev/null 2>&1; then ';
                $command .= "bzip2 -df {$escapedPath}; ";
                $command .= 'else echo "bzip2 not available" && exit 1; ';
                $command .= 'fi';
            }
            // XZ files (xz only, not tar)
            elseif ($extension === 'xz') {
                $command .= 'if command -v unxz >/dev/null 2>&1; then ';
                $command .= "unxz -f {$escapedPath}; ";
                $command .= 'elif command -v xz >/dev/null 2>&1; then ';
                $command .= "xz -df {$escapedPath}; ";
                $command .= 'else echo "xz not available" && exit 1; ';
                $command .= 'fi';
            }
            // TGZ files (alternative extension for tar.gz)
            elseif ($extension === 'tgz') {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v gzip >/dev/null 2>&1; then ';
                $command .= "tar -xzf {$escapedPath}; ";
                $command .= 'else echo "tar/gzip not available" && exit 1; ';
                $command .= 'fi';
            }
            // TBZ2 files (alternative extension for tar.bz2)
            elseif (in_array($extension, ['tbz2', 'tbz'])) {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v bzip2 >/dev/null 2>&1; then ';
                $command .= "tar -xjf {$escapedPath}; ";
                $command .= 'else echo "tar/bzip2 not available" && exit 1; ';
                $command .= 'fi';
            }
            // TXZ files (alternative extension for tar.xz)
            elseif ($extension === 'txz') {
                $command .= 'if command -v tar >/dev/null 2>&1 && command -v xz >/dev/null 2>&1; then ';
                $command .= "tar -xJf {$escapedPath}; ";
                $command .= 'else echo "tar/xz not available" && exit 1; ';
                $command .= 'fi';
            } else {
                $this->dispatch('error', 'Unsupported archive format. Supported: zip, tar, tar.gz, tar.bz2, tar.xz, gz, bz2, xz');

                return;
            }

            $command .= "'";

            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $output = instant_remote_process([$command], $server, false);
            if (str_contains($output ?? '', 'not available')) {
                $this->dispatch('error', 'Required decompression tool not available in container.');

                return;
            }

            $this->dispatch('success', 'File decompressed successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to decompress file: '.$e->getMessage());
        }
    }

    public function moveFile(string $sourcePath, string $destinationPath)
    {
        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $escapedSource = escapeshellarg($sourcePath);
            $escapedDest = escapeshellarg($destinationPath);

            $command = "docker exec {$escapedContainer} sh -c 'mv {$escapedSource} {$escapedDest}'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            instant_remote_process([$command], $server);

            $this->moveSource = null;
            $this->moveDestination = null;
            $this->showMoveDialog = false;
            $this->dispatch('success', 'File moved successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to move file: '.$e->getMessage());
        }
    }

    public function getDownloadUrl(string $path): string
    {
        $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
        if (is_null($container)) {
            return '#';
        }

        $server = data_get($container, 'server');
        $containerName = data_get($container, 'container.Names');

        // Get resource UUID based on type
        $resourceUuid = null;
        if ($this->type === 'application' && isset($this->parameters['application_uuid'])) {
            $resourceUuid = $this->parameters['application_uuid'];
        } elseif ($this->type === 'database' && isset($this->parameters['database_uuid'])) {
            $resourceUuid = $this->parameters['database_uuid'];
        } elseif ($this->type === 'service' && isset($this->parameters['service_uuid'])) {
            $resourceUuid = $this->parameters['service_uuid'];
        }

        if (! $resourceUuid) {
            return '#';
        }

        // Create a temporary token for secure download
        $token = encrypt([
            'container' => $containerName,
            'path' => $path,
            'server_id' => $server->id,
            'resource_type' => $this->type,
            'resource_uuid' => $resourceUuid,
        ]);

        return route('project.file.download', [
            'token' => $token,
        ]);
    }

    public function render()
    {
        return view('livewire.project.shared.file-explorer');
    }
}
