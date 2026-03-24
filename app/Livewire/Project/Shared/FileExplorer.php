<?php

namespace App\Livewire\Project\Shared;

use App\Enums\ActivityTypes;
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

    public $uploadFile = null;

    public ?string $moveSource = null;

    public ?string $moveDestination = null;

    public bool $showMoveDialog = false;

    public array $selectedFiles = [];

    public bool $showCompressDialog = false;

    public ?string $compressArchiveName = null;

    public bool $showExtractDialog = false;

    public ?string $extractArchiveName = null;

    public bool $overwriteExisting = false;

    public bool $showImportDatabaseDialog = false;

    public ?string $importDatabaseFile = null;

    public ?string $importDatabaseContainer = null;

    public bool $isMySQLOrMariaDB = false;

    public bool $showRenameDialog = false;

    public ?string $renameSource = null;

    public ?string $renameNewName = null;

    public bool $hasMySQLOrMariaDBContainer = false;

    public bool $showDatabasePanel = false;

    public ?bool $isUnzipInstalled = null;

    public array $databases = [];

    public ?string $selectedDatabase = null;

    public array $tables = [];

    public ?string $selectedTable = null;

    public array $tableStructure = [];

    public array $tableData = [];

    public int $currentPage = 1;

    public int $perPage = 50;

    public ?string $phpMyAdminUrl = null;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->containers = collect();
        $this->servers = collect();
        $this->files = [];
        $this->selectedFiles = [];
        $this->isMySQLOrMariaDB = false;
        $this->hasMySQLOrMariaDBContainer = false;
        $this->showCreateFolder = false;
        $this->showMoveDialog = false;
        $this->showCompressDialog = false;
        $this->showImportDatabaseDialog = false;
        $this->showDatabasePanel = false;
        $this->databases = [];
        $this->selectedDatabase = null;
        $this->tables = [];
        $this->selectedTable = null;
        $this->tableStructure = [];
        $this->tableData = [];
        $this->currentPage = 1;
        $this->showDatabasePanel = false;
        $this->databases = [];
        $this->selectedDatabase = null;
        $this->tables = [];
        $this->selectedTable = null;
        $this->tableStructure = [];
        $this->tableData = [];
        $this->currentPage = 1;

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
            // Load service with destination and its server relationship
            $this->resource = Service::with(['destination.server'])->where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            // Get server from destination, not directly from service
            $serviceServer = data_get($this->resource, 'destination.server');
            if ($serviceServer && $serviceServer->isFunctional()) {
                $this->servers = $this->servers->push($serviceServer);
            }
            $this->loadContainers();
        }

        $this->servers = $this->servers->sortByDesc(fn ($server) => $server->isTerminalEnabled());

        // Check if any container in the service is MySQL/MariaDB
        $this->checkForDatabaseContainers();

        if ($this->containers->count() === 1) {
            try {
                $this->selected_container = data_get($this->containers->first(), 'container.Names');
                $this->checkDatabaseType();
                $this->loadFiles();
            } catch (\Throwable $e) {
                // Silently fail on mount, user can manually select container
            }
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
                $this->resource->applications()->get()->each(function ($application) use ($server) {
                    if ($application->isRunning() && $server->isTerminalEnabled()) {
                        $this->containers->push([
                            'server' => $server,
                            'container' => [
                                'Names' => data_get($application, 'name').'-'.data_get($this->resource, 'uuid'),
                            ],
                        ]);
                    }
                });
                $this->resource->databases()->get()->each(function ($database) use ($server) {
                    if ($database->isRunning() && $server->isTerminalEnabled()) {
                        $this->containers->push([
                            'server' => $server,
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
            $this->checkDatabaseType();
            $this->checkForDatabaseContainers();
            $this->loadFiles();
            $this->isUnzipInstalled = null;
        } else {
            $this->isMySQLOrMariaDB = false;
            $this->hasMySQLOrMariaDBContainer = false;
            $this->isUnzipInstalled = null;
        }
    }

    public function checkForDatabaseContainers()
    {
        $this->hasMySQLOrMariaDBContainer = false;

        foreach ($this->containers as $container) {
            $containerName = data_get($container, 'container.Names', '');

            // Check by name
            if (str_contains(strtolower($containerName), 'mysql') ||
                str_contains(strtolower($containerName), 'mariadb')) {
                $this->hasMySQLOrMariaDBContainer = true;
                return;
            }

            // Check by image
            try {
                $server = data_get($container, 'server');
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker inspect {$escapedContainer} --format='{{.Config.Image}}'";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $image = trim(instant_remote_process([$command], $server, false) ?? '');
                if (str_contains(strtolower($image), 'mysql') ||
                    str_contains(strtolower($image), 'mariadb')) {
                    $this->hasMySQLOrMariaDBContainer = true;
                    return;
                }
            } catch (\Throwable $e) {
                // Continue checking other containers
            }
        }
    }

    public function checkDatabaseType()
    {
        if ($this->selected_container === 'default') {
            $this->isMySQLOrMariaDB = false;

            return;
        }

        $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
        if (is_null($container)) {
            $this->isMySQLOrMariaDB = false;

            return;
        }

        $containerName = data_get($container, 'container.Names', '');
        $server = data_get($container, 'server');

        // Check if container name contains mysql or mariadb
        $this->isMySQLOrMariaDB = str_contains(strtolower($containerName), 'mysql') ||
                                   str_contains(strtolower($containerName), 'mariadb');

        // If not found by name, try to check container image
        if (! $this->isMySQLOrMariaDB) {
            try {
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker inspect {$escapedContainer} --format='{{.Config.Image}}'";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $image = trim(instant_remote_process([$command], $server, false) ?? '');
                $this->isMySQLOrMariaDB = str_contains(strtolower($image), 'mysql') ||
                                         str_contains(strtolower($image), 'mariadb');
            } catch (\Throwable $e) {
                // Continue to next check
            }
        }

        // Check environment variables for MySQL/MariaDB indicators (including WordPress DB connections)
        if (! $this->isMySQLOrMariaDB) {
            try {
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} env 2>/dev/null";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $envOutput = instant_remote_process([$command], $server, false) ?? '';

                // Check for MySQL/MariaDB environment variables
                if (str_contains($envOutput, 'MYSQL_ROOT_PASSWORD') ||
                    str_contains($envOutput, 'MARIADB_ROOT_PASSWORD') ||
                    str_contains($envOutput, 'MYSQL_DATABASE') ||
                    str_contains($envOutput, 'MARIADB_DATABASE') ||
                    str_contains($envOutput, 'WORDPRESS_DB_HOST=mysql') ||
                    str_contains($envOutput, 'WORDPRESS_DB_HOST=mariadb') ||
                    str_contains($envOutput, 'WORDPRESS_DB_HOST=localhost') ||
                    str_contains($envOutput, 'WORDPRESS_DB_HOST=127.0.0.1')) {
                    $this->isMySQLOrMariaDB = true;
                }
            } catch (\Throwable $e) {
                // Continue to next check
            }
        }

        // Check if mysql or mariadb commands are available in the container (for embedded databases)
        if (! $this->isMySQLOrMariaDB) {
            try {
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'command -v mysql >/dev/null 2>&1 && echo mysql || command -v mariadb >/dev/null 2>&1 && echo mariadb || echo notfound'";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $output = trim(instant_remote_process([$command], $server, false) ?? '');
                $this->isMySQLOrMariaDB = ($output === 'mysql' || $output === 'mariadb');
            } catch (\Throwable $e) {
                // Continue to next check
            }
        }

        // Check if MySQL/MariaDB process is running inside the container (for embedded databases like WordPress)
        if (! $this->isMySQLOrMariaDB) {
            try {
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'ps aux | grep -E \"(mysqld|mariadbd)\" | grep -v grep || echo notfound'";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $output = trim(instant_remote_process([$command], $server, false) ?? '');
                $this->isMySQLOrMariaDB = ($output !== 'notfound' && ! empty($output));
            } catch (\Throwable $e) {
                // Continue to next check
            }
        }

        // Check if MySQL socket or data directory exists (for embedded databases)
        if (! $this->isMySQLOrMariaDB) {
            try {
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'test -d /var/lib/mysql || test -d /var/lib/mariadb || test -S /var/run/mysqld/mysqld.sock || test -S /run/mysqld/mysqld.sock && echo found || echo notfound'";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $output = trim(instant_remote_process([$command], $server, false) ?? '');
                $this->isMySQLOrMariaDB = ($output === 'found');
            } catch (\Throwable $e) {
                // Continue to next check
            }
        }

        // Check if WordPress wp-config.php references MySQL/MariaDB
        if (! $this->isMySQLOrMariaDB) {
            try {
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/wp-config.php && grep -iE \"(DB_HOST|DB_NAME|DB_USER|DB_PASSWORD)\" /var/www/html/wp-config.php 2>/dev/null | head -1 || echo notfound'";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $output = trim(instant_remote_process([$command], $server, false) ?? '');
                // If wp-config.php exists and has DB settings, likely has MySQL/MariaDB
                if ($output !== 'notfound' && ! empty($output)) {
                    $this->isMySQLOrMariaDB = true;
                }
            } catch (\Throwable $e) {
                // Continue to next check
            }
        }

        // If still not found and it's a service, check if there are database containers in the same service
        if (! $this->isMySQLOrMariaDB && $this->type === 'service') {
            try {
                // Check all containers in the service for MySQL/MariaDB
                foreach ($this->containers as $serviceContainer) {
                    $serviceContainerName = data_get($serviceContainer, 'container.Names', '');
                    if (str_contains(strtolower($serviceContainerName), 'mysql') ||
                        str_contains(strtolower($serviceContainerName), 'mariadb')) {
                        $this->isMySQLOrMariaDB = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Silently fail
            }
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
            $command = "docker exec {$escapedContainer} sh -c 'ls -la {$escapedPath} 2>/dev/null | tail -n +2'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $output = instant_remote_process([$command], $server, false);

            $this->files = $this->parseFileList($output ?? '');
            $this->syncSelectedFilesWithCurrentDirectory();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load files: '.$e->getMessage());
            $this->files = [];
            $this->selectedFiles = [];
            $this->isLoading = false;
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

                $fileData = [
                    'name' => $name,
                    'path' => $fullPath,
                    'is_directory' => $isDirectory,
                    'size' => $isDirectory ? '-' : $this->formatSize((int) $size),
                    'permissions' => $permissions,
                    'date' => $date,
                ];

                // Add download URL for files (not directories)
                if (!$isDirectory && !empty($fullPath)) {
                    try {
                        $fileData['download_url'] = $this->getDownloadUrl($fullPath);
                    } catch (\Throwable $e) {
                        $fileData['download_url'] = '#';
                    }
                } else {
                    $fileData['download_url'] = '#';
                }

                $files[] = $fileData;
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

    public function getFileLanguage(?string $path): string
    {
        if (empty($path)) {
            return 'plaintext';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $filename = strtolower(basename($path));

        // Map extensions to Monaco Editor languages
        $languageMap = [
            'php' => 'php',
            'js' => 'javascript',
            'jsx' => 'javascript',
            'ts' => 'typescript',
            'tsx' => 'typescript',
            'json' => 'json',
            'css' => 'css',
            'scss' => 'scss',
            'sass' => 'sass',
            'html' => 'html',
            'xml' => 'xml',
            'yaml' => 'yaml',
            'yml' => 'yaml',
            'md' => 'markdown',
            'sh' => 'shell',
            'bash' => 'shell',
            'py' => 'python',
            'rb' => 'ruby',
            'java' => 'java',
            'cpp' => 'cpp',
            'c' => 'c',
            'h' => 'c',
            'sql' => 'sql',
            'vue' => 'html',
            'env' => 'plaintext',
            'conf' => 'plaintext',
            'ini' => 'ini',
            'log' => 'plaintext',
            'txt' => 'plaintext',
        ];

        // Special cases for filenames
        if ($filename === 'dockerfile') {
            return 'dockerfile';
        }
        if ($filename === '.env' || str_ends_with($filename, '.env')) {
            return 'plaintext';
        }
        if ($filename === 'wp-config.php') {
            return 'php';
        }

        return $languageMap[$extension] ?? 'plaintext';
    }

    public function openFile(string $path)
    {
        $file = collect($this->files)->firstWhere('path', $path);
        if (! $file || $file['is_directory']) {
            return;
        }

        // Check if it's a text file
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $textExtensions = ['txt', 'php', 'env', 'js', 'json', 'css', 'html', 'xml', 'yaml', 'yml', 'md', 'log', 'conf', 'ini', 'sh', 'bash', 'py', 'rb', 'java', 'cpp', 'c', 'h', 'sql', 'vue', 'ts', 'tsx', 'jsx', 'dockerfile'];

        if (! in_array($extension, $textExtensions) && ! in_array(strtolower(basename($path)), ['dockerfile', '.env', 'wp-config.php'])) {
            $this->dispatch('error', 'This file type cannot be edited. Only text files are supported.');

            return;
        }

        $this->selectedFile = $path;
        $this->isEditing = true; // Start in edit mode directly
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

    public function getSelectedServerId(): ?int
    {
        $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
        if (is_null($container)) {
            return null;
        }
        $server = data_get($container, 'server');

        return $server?->id;
    }

    public function onChunkedUploadComplete()
    {
        $this->dispatch('success', 'File uploaded successfully.');
        $this->loadFiles();
    }

    public function updatedUploadFile()
    {
        // This method is automatically called by Livewire when uploadFile changes
        if (! $this->uploadFile) {
            return;
        }

        // Check if it's actually a file upload
        if (! is_object($this->uploadFile) || ! method_exists($this->uploadFile, 'getClientOriginalName')) {
            $this->dispatch('error', 'Invalid file upload.');
            $this->uploadFile = null;

            return;
        }

        try {
            $this->validate([
                'uploadFile' => 'required|file|max:102400', // Max 100MB
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $errorMessage = collect($errors)->flatten()->first() ?? 'File validation failed';
            $this->dispatch('error', $errorMessage);
            $this->uploadFile = null;

            return;
        }

        try {
            if ($this->selected_container === 'default') {
                $this->dispatch('error', 'Please select a container first.');
                $this->uploadFile = null;

                return;
            }

            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found. Please select a valid container.');

                return;
            }

            $server = data_get($container, 'server');
            if (is_null($server)) {
                $this->dispatch('error', 'Server information not found for this container.');
                $this->uploadFile = null;

                return;
            }

            $containerName = data_get($container, 'container.Names');
            if (empty($containerName)) {
                $this->dispatch('error', 'Container name not found.');
                $this->uploadFile = null;

                return;
            }

            $escapedContainer = escapeshellarg($containerName);

            // Save uploaded file temporarily
            $filename = $this->uploadFile->getClientOriginalName();

            // Ensure temp directory exists
            if (! Storage::disk('local')->exists('temp')) {
                Storage::disk('local')->makeDirectory('temp');
            }

            $tmpPath = $this->uploadFile->storeAs('temp', uniqid().'_'.$filename, 'local');
            $fullTmpPath = Storage::disk('local')->path($tmpPath);

            if (! file_exists($fullTmpPath)) {
                throw new \Exception('Failed to save uploaded file temporarily.');
            }

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
            \Log::error('File upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->dispatch('error', 'Failed to upload file: '.$e->getMessage());
            $this->uploadFile = null;
        }
    }

    public function deleteFile(string $path, string $password = '')
    {
        try {
            if (! $this->deleteFileInternal($path)) {
                $this->dispatch('error', 'Failed to delete file or folder.');

                return;
            }

            $this->dispatch('success', 'File deleted successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete file: '.$e->getMessage());
        }
    }

    public function deleteFileByEncodedPath(string $encodedPath, string $password = '')
    {
        try {
            $decodedPath = $this->decodePathFromEncoded($encodedPath);
            if ($decodedPath === null || $decodedPath === '') {
                $this->dispatch('error', 'Invalid file path.');

                return;
            }

            $this->deleteFile($decodedPath, $password);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to decode file path: '.$e->getMessage());
        }
    }

    public function decompressFileByEncodedPath(string $encodedPath): void
    {
        $decodedPath = $this->decodePathFromEncoded($encodedPath);
        if ($decodedPath === null || $decodedPath === '') {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        $this->decompressFile($decodedPath);
    }

    public function openRenameDialogByEncodedPath(string $encodedPath): void
    {
        $decodedPath = $this->decodePathFromEncoded($encodedPath);
        if ($decodedPath === null || $decodedPath === '') {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        $this->openRenameDialog($decodedPath);
    }

    public function openMoveDialogByEncodedPath(string $encodedPath): void
    {
        $decodedPath = $this->decodePathFromEncoded($encodedPath);
        if ($decodedPath === null || $decodedPath === '') {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        $this->openMoveDialog($decodedPath);
    }

    private function deleteFileInternal(string $path): bool
    {
        // modal-confirmation can pass string params wrapped in quotes.
        // Normalize here so rm targets the real filesystem path.
        $normalizedPath = trim($path);
        if (
            (str_starts_with($normalizedPath, "'") && str_ends_with($normalizedPath, "'")) ||
            (str_starts_with($normalizedPath, '"') && str_ends_with($normalizedPath, '"'))
        ) {
            $normalizedPath = substr($normalizedPath, 1, -1);
        }

        if ($normalizedPath === '') {
            return false;
        }

        $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
        if (is_null($container)) {
            return false;
        }

        $server = data_get($container, 'server');
        $containerName = data_get($container, 'container.Names');
        if (! $server || ! $server instanceof Server) {
            return false;
        }

        if (! $this->pathExistsInContainer($containerName, $server, $normalizedPath)) {
            return false;
        }

        $escapedContainer = escapeshellarg($containerName);
        $escapedPath = escapeshellarg($normalizedPath);

        $command = "docker exec {$escapedContainer} rm -rf {$escapedPath}";
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }

        instant_remote_process([$command], $server);

        if ($this->pathExistsInContainer($containerName, $server, $normalizedPath)) {
            return false;
        }

        if ($this->selectedFile === $normalizedPath || $this->selectedFile === $path) {
            $this->selectedFile = null;
            $this->fileContent = null;
            $this->isEditing = false;
        }

        return true;
    }

    private function pathExistsInContainer(string $containerName, Server $server, string $path): bool
    {
        $escapedContainer = escapeshellarg($containerName);
        $escapedPath = escapeshellarg($path);
        $checkCommand = "docker exec {$escapedContainer} sh -c 'test -e {$escapedPath} && echo exists || echo missing'";

        if ($server->isNonRoot()) {
            $checkCommand = "sudo {$checkCommand}";
        }

        $result = trim((string) (instant_remote_process([$checkCommand], $server, false) ?? ''));

        return $result === 'exists';
    }

    private function syncSelectedFilesWithCurrentDirectory(): void
    {
        $validPaths = collect($this->files)
            ->pluck('path')
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->toArray();

        $validPathsSet = array_flip($validPaths);
        $normalizedSelected = [];
        foreach ($this->selectedFiles as $path) {
            if (is_string($path) && isset($validPathsSet[$path])) {
                $normalizedSelected[$path] = $path;
            }
        }

        $this->selectedFiles = array_values($normalizedSelected);
    }

    public function toggleFileSelection(string $path)
    {
        if (in_array($path, $this->selectedFiles, true)) {
            $this->selectedFiles = array_values(array_diff($this->selectedFiles, [$path]));
        } else {
            $this->selectedFiles[] = $path;
        }
        $this->syncSelectedFilesWithCurrentDirectory();
    }

    public function updatedSelectedFiles(): void
    {
        $this->syncSelectedFilesWithCurrentDirectory();
    }

    public function selectAll()
    {
        $this->syncSelectedFilesWithCurrentDirectory();
        $allSelected = count($this->selectedFiles) === count($this->files);
        if ($allSelected) {
            $this->selectedFiles = [];
        } else {
            $this->selectedFiles = collect($this->files)
                ->pluck('path')
                ->filter(fn ($path) => is_string($path) && $path !== '')
                ->unique()
                ->values()
                ->toArray();
        }
    }

    public function deselectAll()
    {
        $this->selectedFiles = [];
    }

    public function deleteSelectedFiles(string $password = ''): void
    {
        $this->syncSelectedFilesWithCurrentDirectory();

        if (empty($this->selectedFiles)) {
            $this->dispatch('error', 'Please select at least one file or folder.');

            return;
        }

        try {
            $deletedCount = 0;
            $failedCount = 0;
            foreach ($this->selectedFiles as $path) {
                if ($this->deleteFileInternal($path)) {
                    $deletedCount++;
                } else {
                    $failedCount++;
                }
            }

            $this->selectedFiles = [];
            $this->loadFiles();

            if ($deletedCount > 0 && $failedCount === 0) {
                $this->dispatch('success', $deletedCount.' item(s) deleted successfully.');
            } elseif ($deletedCount > 0) {
                $this->dispatch('error', "Deleted {$deletedCount} item(s), but {$failedCount} could not be deleted.");
            } else {
                $this->dispatch('error', 'No selected items could be deleted.');
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete selected items: '.$e->getMessage());
        }
    }

    public function extractSelectedFiles()
    {
        if (count($this->selectedFiles) !== 1) {
            $this->dispatch('error', 'Please select exactly one file to extract.');

            return;
        }

        $filePath = $this->selectedFiles[0];

        if (! preg_match('/\.(zip|tar|tar\.gz|tar\.bz2|tar\.xz|tgz|tbz2|tbz|txz|gz)$/i', $filePath)) {
            $this->dispatch('error', 'Please select a supported compressed file (.zip, .tar.gz, etc.)');

            return;
        }

        $this->extractArchiveName = basename($filePath);
        $this->showExtractDialog = true;
    }

    public function executeExtraction()
    {
        // Increase PHP execution time for long operations
        set_time_limit(3600);
        ini_set('max_execution_time', '3600');

        if (count($this->selectedFiles) !== 1) {
            $this->dispatch('error', 'Please select exactly one file to extract.');
            $this->showExtractDialog = false;

            return;
        }

        $filePath = $this->selectedFiles[0];

        if (! preg_match('/\.(zip|tar|tar\.gz|tar\.bz2|tar\.xz|tgz|tbz2|tbz|txz|gz)$/i', $filePath)) {
            $this->dispatch('error', 'Please select a supported compressed file (.zip, .tar.gz, etc.)');
            $this->showExtractDialog = false;

            return;
        }

        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');
                $this->showExtractDialog = false;

                return;
            }

            $server = data_get($container, 'server');
            if (is_null($server)) {
                $this->dispatch('error', 'Server not found.');
                $this->showExtractDialog = false;

                return;
            }

            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $archiveFileName = basename($filePath);
            $fileNameEscaped = escapeshellarg($archiveFileName);
            $archiveFileNameForPython = str_replace(['\\', "'"], ['\\\\', "\\'"], $archiveFileName);
            $archiveFileNameForPhp = str_replace(['\\', "'"], ['\\\\', "\\'"], $archiveFileName);
            $fileDir = dirname($filePath);
            $fileDirEscaped = escapeshellarg($fileDir);

            // Build extraction command with automatic tool installation
            $extractionCommand = '';

            if (str_ends_with(strtolower($filePath), '.zip')) {
                // Try multiple methods: unzip command, Python, or PHP
                // Para archivos grandes, ejecutar con output periódico para mantener conexión activa
                $extractionCommand = "cd {$fileDirEscaped} && ";
                $extractionCommand .= "if command -v unzip >/dev/null 2>&1; then ";
                // Ejecutar unzip en background con output periódico usando un script temporal
                // Esto mantiene la conexión activa y permite capturar el resultado
                $extractionCommand .= "(unzip -o {$fileNameEscaped} -d . 2>&1 | while IFS= read -r line; do echo \"PROGRESS: \\\$line\"; done; echo 'EXTRACTION_SUCCESS') || echo 'EXTRACTION_FAILED'; ";
                $extractionCommand .= "elif command -v python3 >/dev/null 2>&1; then ";
                // Python con output periódico cada 100 archivos
                $extractionCommand .= "python3 -c \"import zipfile, os, sys; z=zipfile.ZipFile('{$archiveFileNameForPython}'); files=z.namelist(); total=len(files); [z.extract(f, '.') or (print(f'PROGRESS: Extracted {i+1}/{total}') if (i+1)%100==0 else None) for i, f in enumerate(files)]; z.close(); print('EXTRACTION_SUCCESS')\" 2>&1 || echo 'EXTRACTION_FAILED'; ";
                $extractionCommand .= "elif command -v python >/dev/null 2>&1; then ";
                $extractionCommand .= "python -c \"import zipfile, os, sys; z=zipfile.ZipFile('{$archiveFileNameForPython}'); files=z.namelist(); total=len(files); [z.extract(f, '.') or (print(f'PROGRESS: Extracted {i+1}/{total}') if (i+1)%100==0 else None) for i, f in enumerate(files)]; z.close(); print('EXTRACTION_SUCCESS')\" 2>&1 || echo 'EXTRACTION_FAILED'; ";
                $extractionCommand .= "elif command -v php >/dev/null 2>&1; then ";
                $extractionCommand .= "php -r \"\\\$zip = new ZipArchive(); if (\\\$zip->open('{$archiveFileNameForPhp}') === TRUE) { \\\$total = \\\$zip->numFiles; for (\\\$i = 0; \\\$i < \\\$total; \\\$i++) { \\\$zip->extractTo('.', [\\\$zip->getNameIndex(\\\$i)]); if ((\\\$i+1) % 100 == 0) echo 'PROGRESS: Extracted ' . (\\\$i+1) . '/' . \\\$total . ' files...' . PHP_EOL; } \\\$zip->close(); echo 'EXTRACTION_SUCCESS'; } else { echo 'EXTRACTION_FAILED'; }\" 2>&1; ";
                $extractionCommand .= "else ";
                $extractionCommand .= "echo 'TOOL_NOT_FOUND:unzip'; ";
                $extractionCommand .= "fi";
            } elseif (preg_match('/\.(tar\.gz|tgz)$/i', $filePath)) {
                $extractionCommand = "cd {$fileDirEscaped} && ";
                $extractionCommand .= "if command -v tar >/dev/null 2>&1; then ";
                $extractionCommand .= "tar -xzf {$fileNameEscaped} -C . 2>&1 && echo 'EXTRACTION_SUCCESS'; ";
                $extractionCommand .= "else echo 'TOOL_NOT_FOUND:tar'; fi";
            } elseif (preg_match('/\.(tar\.bz2|tbz2|tbz)$/i', $filePath)) {
                $extractionCommand = "cd {$fileDirEscaped} && ";
                $extractionCommand .= "if command -v tar >/dev/null 2>&1; then ";
                $extractionCommand .= "tar -xjf {$fileNameEscaped} -C . 2>&1 && echo 'EXTRACTION_SUCCESS'; ";
                $extractionCommand .= "else echo 'TOOL_NOT_FOUND:tar'; fi";
            } elseif (preg_match('/\.(tar\.xz|txz)$/i', $filePath)) {
                $extractionCommand = "cd {$fileDirEscaped} && ";
                $extractionCommand .= "if command -v tar >/dev/null 2>&1; then ";
                $extractionCommand .= "tar -xJf {$fileNameEscaped} -C . 2>&1 && echo 'EXTRACTION_SUCCESS'; ";
                $extractionCommand .= "else echo 'TOOL_NOT_FOUND:tar'; fi";
            } elseif (str_ends_with(strtolower($filePath), '.tar')) {
                $extractionCommand = "cd {$fileDirEscaped} && ";
                $extractionCommand .= "if command -v tar >/dev/null 2>&1; then ";
                $extractionCommand .= "tar -xf {$fileNameEscaped} -C . 2>&1 && echo 'EXTRACTION_SUCCESS'; ";
                $extractionCommand .= "else echo 'TOOL_NOT_FOUND:tar'; fi";
            } elseif (str_ends_with(strtolower($filePath), '.gz')) {
                $extractionCommand = "cd {$fileDirEscaped} && ";
                $extractionCommand .= "if command -v gzip >/dev/null 2>&1; then ";
                $extractionCommand .= "gzip -d -k {$fileNameEscaped} 2>&1 && echo 'EXTRACTION_SUCCESS'; ";
                $extractionCommand .= "else echo 'TOOL_NOT_FOUND:gzip'; fi";
            } else {
                $this->dispatch('error', 'Unsupported archive format.');
                $this->showExtractDialog = false;
                return;
            }

            $innerCommand = $extractionCommand;

            $command = "docker exec {$escapedContainer} sh -c " . escapeshellarg($innerCommand);

            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            // Para archivos grandes, usar timeout extendido (2 horas = 7200 segundos)
            // y deshabilitar multiplexing para evitar problemas de conexión
            $extendedTimeout = 7200; // 2 horas para archivos grandes

            // Notificar al usuario sobre archivos grandes
            $this->dispatch('info', 'Extrayendo archivo. Esto puede tardar varios minutos para archivos grandes...');

            // Execute extraction with extended timeout and disabled multiplexing for large files
            $output = instant_remote_process([$command], $server, false, false, $extendedTimeout, true);
            $output = trim($output ?? '');

            // Check if extraction was successful
            if (str_contains($output, 'EXTRACTION_SUCCESS')) {
                $this->dispatch('success', 'File extracted successfully.');
                // Refresh file list to show extracted files
                $this->loadFiles();
            } elseif (str_contains($output, 'TOOL_NOT_FOUND:')) {
                $tool = str_replace('TOOL_NOT_FOUND:', '', $output);
                $this->dispatch('error', "Required tool not found in container: {$tool}. Please install it first.");
            } elseif (str_contains($output, 'EXTRACTION_FAILED')) {
                // Show error output from extraction command
                $this->dispatch('error', 'Extraction failed: '.$output);
            } elseif (!empty($output)) {
                // Si hay output pero no contiene EXTRACTION_SUCCESS, puede ser un error parcial
                $this->dispatch('error', 'Extraction may have failed. Output: '.substr($output, 0, 500));
            } else {
                // No output puede indicar timeout o conexión perdida para archivos muy grandes
                $this->dispatch('error', 'Extraction failed. No output received from container. This may indicate a timeout for very large files. Please try again or extract the file manually using the terminal.');
            }

            $this->selectedFiles = [];
            $this->showExtractDialog = false;
            return;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to extract file. Ensure the container has the required tools (e.g., unzip, tar). Error: ' . $e->getMessage());
            $this->showExtractDialog = false;
        }
    }

    public function checkUnzip(): void
    {
        try {
            $context = $this->getSelectedContainerContext();
            if ($context === null) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            $checkCommand = "docker exec {$escapedContainer} sh -c 'command -v unzip >/dev/null 2>&1 && echo INSTALLED || echo NOT_INSTALLED'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }

            $checkResult = trim(instant_remote_process([$checkCommand], $server, false) ?? '');
            if ($checkResult === 'INSTALLED') {
                $this->isUnzipInstalled = true;
                $this->dispatch('success', 'unzip is already installed in this container.');
            } else {
                $this->isUnzipInstalled = false;
                $this->dispatch('error', 'unzip is not installed in this container.');
            }
        } catch (\Throwable $e) {
            $this->isUnzipInstalled = null;
            $this->dispatch('error', 'Failed to check unzip: '.$e->getMessage());
        }
    }

    public function installUnzip(): void
    {
        try {
            $context = $this->getSelectedContainerContext();
            if ($context === null) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            $this->dispatch('info', 'Installing unzip... This may take a moment.');

            $installCommand = "docker exec {$escapedContainer} sh -c '";
            $installCommand .= "if command -v apk >/dev/null 2>&1; then ";
            $installCommand .= "apk add --no-cache unzip 2>&1 && echo INSTALL_SUCCESS || echo INSTALL_FAILED; ";
            $installCommand .= "elif command -v apt-get >/dev/null 2>&1; then ";
            $installCommand .= "apt-get update -qq && apt-get install -y -qq unzip 2>&1 && echo INSTALL_SUCCESS || echo INSTALL_FAILED; ";
            $installCommand .= "elif command -v yum >/dev/null 2>&1; then ";
            $installCommand .= "yum install -y -q unzip 2>&1 && echo INSTALL_SUCCESS || echo INSTALL_FAILED; ";
            $installCommand .= "elif command -v dnf >/dev/null 2>&1; then ";
            $installCommand .= "dnf install -y -q unzip 2>&1 && echo INSTALL_SUCCESS || echo INSTALL_FAILED; ";
            $installCommand .= "else echo NO_PACKAGE_MANAGER; ";
            $installCommand .= "fi'";

            if ($server->isNonRoot()) {
                $installCommand = "sudo {$installCommand}";
            }

            $installResult = trim((string) (instant_remote_process([$installCommand], $server, false) ?? ''));
            if (str_contains($installResult, 'INSTALL_SUCCESS')) {
                $this->checkUnzip();
                if ($this->isUnzipInstalled === true) {
                    $this->dispatch('success', 'unzip has been successfully installed in this container.');
                } else {
                    $this->dispatch('error', 'unzip installation completed but verification failed. Please try restarting the container.');
                }
            } elseif (str_contains($installResult, 'NO_PACKAGE_MANAGER')) {
                $this->isUnzipInstalled = false;
                $this->dispatch('error', 'Could not find a supported package manager (apk, apt-get, yum, dnf) in this container. Please install unzip manually.');
            } else {
                $this->isUnzipInstalled = false;
                $this->dispatch('error', 'Failed to install unzip. Error: '.$installResult);
            }
        } catch (\Throwable $e) {
            $this->isUnzipInstalled = null;
            $this->dispatch('error', 'Failed to install unzip: '.$e->getMessage());
        }
    }

    public function openCompressDialog()
    {
        $this->syncSelectedFilesWithCurrentDirectory();

        if (empty($this->selectedFiles)) {
            $this->dispatch('error', 'Please select at least one file or folder to compress.');

            return;
        }

        $this->compressArchiveName = 'archive_'.date('Y-m-d_His').'.zip';
        $this->showCompressDialog = true;
    }

    public function compressSelectedFiles()
    {
        $this->syncSelectedFilesWithCurrentDirectory();

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

            // Keep archive creation inside current directory.
            $archiveFileName = $this->compressArchiveName;
            $archivePath = rtrim($dirPath, '/').'/'.$archiveFileName;
            if ($dirPath === '/') {
                $archivePath = '/'.$archiveFileName;
            }
            $escapedArchive = escapeshellarg($archivePath);
            $escapedArchiveFileName = escapeshellarg($archiveFileName);

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
            $fileNames = collect($this->selectedFiles)
                ->map(fn ($selectedPath) => $this->normalizePathArgument((string) $selectedPath))
                ->filter(fn ($selectedPath) => $selectedPath !== '')
                ->map(fn ($selectedPath) => escapeshellarg(basename($selectedPath)))
                ->unique()
                ->values()
                ->toArray();

            if (empty($fileNames)) {
                $this->dispatch('error', 'No valid files selected.');
                $this->showCompressDialog = false;

                return;
            }

            $filesList = implode(' ', $fileNames);

            // Determine compression format
            $extension = strtolower(pathinfo($this->compressArchiveName, PATHINFO_EXTENSION));
            $baseExtension = strtolower(pathinfo(pathinfo($this->compressArchiveName, PATHINFO_FILENAME), PATHINFO_EXTENSION));

            $innerCommand = "cd {$escapedDir} && ";

            if ($extension === 'zip') {
                $innerCommand .= 'if command -v zip >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $innerCommand .= "rm -f {$escapedArchiveFileName} && ";
                }
                $innerCommand .= "zip -r {$escapedArchiveFileName} {$filesList} 2>&1; ";
                $innerCommand .= 'else echo "zip not available" && exit 1; ';
                $innerCommand .= 'fi';
            } elseif (in_array($extension, ['gz', 'tgz']) || ($extension === 'gz' && str_ends_with($baseExtension, '.tar'))) {
                $innerCommand .= 'if command -v tar >/dev/null 2>&1 && command -v gzip >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $innerCommand .= "rm -f {$escapedArchiveFileName} && ";
                }
                $innerCommand .= "tar -czf {$escapedArchiveFileName} {$filesList} 2>&1; ";
                $innerCommand .= 'else echo "tar/gzip not available" && exit 1; ';
                $innerCommand .= 'fi';
            } elseif (in_array($extension, ['bz2', 'tbz2', 'tbz']) || ($extension === 'bz2' && str_ends_with($baseExtension, '.tar'))) {
                $innerCommand .= 'if command -v tar >/dev/null 2>&1 && command -v bzip2 >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $innerCommand .= "rm -f {$escapedArchiveFileName} && ";
                }
                $innerCommand .= "tar -cjf {$escapedArchiveFileName} {$filesList} 2>&1; ";
                $innerCommand .= 'else echo "tar/bzip2 not available" && exit 1; ';
                $innerCommand .= 'fi';
            } elseif (in_array($extension, ['xz', 'txz']) || ($extension === 'xz' && str_ends_with($baseExtension, '.tar'))) {
                $innerCommand .= 'if command -v tar >/dev/null 2>&1 && command -v xz >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $innerCommand .= "rm -f {$escapedArchiveFileName} && ";
                }
                $innerCommand .= "tar -cJf {$escapedArchiveFileName} {$filesList} 2>&1; ";
                $innerCommand .= 'else echo "tar/xz not available" && exit 1; ';
                $innerCommand .= 'fi';
            } elseif ($extension === 'tar') {
                $innerCommand .= 'if command -v tar >/dev/null 2>&1; then ';
                if ($this->overwriteExisting && $exists) {
                    $innerCommand .= "rm -f {$escapedArchiveFileName} && ";
                }
                $innerCommand .= "tar -cf {$escapedArchiveFileName} {$filesList} 2>&1; ";
                $innerCommand .= 'else echo "tar not available" && exit 1; ';
                $innerCommand .= 'fi';
            } else {
                $this->dispatch('error', 'Unsupported archive format. Use .zip, .tar, .tar.gz, .tar.bz2, or .tar.xz');
                $this->showCompressDialog = false;

                return;
            }

            $wrappedCommand = $innerCommand.'; __coolify_exit=$?; echo "__COMPRESS_EXIT:${__coolify_exit}__"';
            $command = "docker exec {$escapedContainer} sh -c " . escapeshellarg($wrappedCommand);

            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $output = (string) (instant_remote_process([$command], $server, false) ?? '');
            if (str_contains($output ?? '', 'not available')) {
                $this->dispatch('error', 'Required compression tool not available in container.');
                $this->showCompressDialog = false;

                return;
            }

            if (preg_match('/__COMPRESS_EXIT:(\d+)__/', $output, $matches) === 1) {
                $exitCode = (int) ($matches[1] ?? 1);
                if ($exitCode !== 0) {
                    $preview = trim(str_replace($matches[0], '', $output));
                    $preview = mb_substr($preview, 0, 400);
                    $this->dispatch('error', $preview !== '' ? 'Compression failed. '.$preview : "Compression failed with exit code {$exitCode}.");
                    $this->showCompressDialog = false;

                    return;
                }
            }

            $verifyArchiveCommand = "docker exec {$escapedContainer} sh -c 'test -f {$escapedArchive} && echo CREATED || echo MISSING'";
            if ($server->isNonRoot()) {
                $verifyArchiveCommand = "sudo {$verifyArchiveCommand}";
            }
            $verifyArchiveResult = trim((string) (instant_remote_process([$verifyArchiveCommand], $server, false) ?? ''));
            if ($verifyArchiveResult !== 'CREATED') {
                $preview = mb_substr(trim((string) $output), 0, 400);
                $this->dispatch('error', $preview !== '' ? 'Compression failed. '.$preview : 'Compression failed. Archive file was not created.');
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
        $normalizedPath = $this->normalizePathArgument($path);
        if ($normalizedPath === '') {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        $this->selectedFiles = [$normalizedPath];
        $this->openCompressDialog();
    }

    public function compressFileByEncodedPath(string $encodedPath): void
    {
        $decodedPath = $this->decodePathFromEncoded($encodedPath);
        if ($decodedPath === null || $decodedPath === '') {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        $this->compressFile($decodedPath);
    }

    public function decompressFile(string $path)
    {
        $normalizedPath = $this->normalizePathArgument($path);
        if ($normalizedPath === '') {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        $this->selectedFiles = [$normalizedPath];
        $this->extractSelectedFiles();
    }

    public function moveFile()
    {
        // Get values from component properties
        $sourcePath = $this->normalizePathArgument((string) $this->moveSource);
        $destinationPath = $this->normalizePathArgument((string) $this->moveDestination);

        if (empty($sourcePath) || empty($destinationPath)) {
            $this->dispatch('error', 'Source and destination paths are required.');

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

    public function openImportDatabaseDialog()
    {
        if ($this->selected_container === 'default') {
            $this->dispatch('error', 'Please select a container first.');

            return;
        }

        // Ensure we have the latest container list and check database type
        $this->loadContainers();
        $this->checkForDatabaseContainers();
        $this->checkDatabaseType();

        // Verify that the selected container or any available container is MySQL/MariaDB
        $databaseContainers = $this->getDatabaseContainers();
        if (! $this->isMySQLOrMariaDB && ! $this->hasMySQLOrMariaDBContainer && count($databaseContainers) === 0) {
            $this->dispatch('error', 'No MySQL or MariaDB container detected. Please make sure you have selected a container with MySQL/MariaDB installed.');

            return;
        }

        $this->showImportDatabaseDialog = true;
    }

    public function importDatabase()
    {
        if (empty($this->importDatabaseFile)) {
            $this->dispatch('error', 'Please select a database file to import.');

            return;
        }

        try {
            // Use selected database container or fallback to current container
            $targetContainerName = $this->importDatabaseContainer ?? $this->selected_container;

            $container = collect($this->containers)->firstWhere('container.Names', $targetContainerName);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');
                $this->showImportDatabaseDialog = false;

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');
            $escapedContainer = escapeshellarg($containerName);
            $escapedPath = escapeshellarg($this->importDatabaseFile);

            // Get environment variables to determine database type and credentials
            $envCommand = "docker exec {$escapedContainer} env";
            if ($server->isNonRoot()) {
                $envCommand = "sudo {$envCommand}";
            }
            $envOutput = instant_remote_process([$envCommand], $server, false) ?? '';
            $envVars = [];
            foreach (explode("\n", $envOutput) as $line) {
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[$key] = $value;
                }
            }

            // Determine database type and build import command
            $isMariaDB = isset($envVars['MARIADB_ROOT_PASSWORD']) || isset($envVars['MARIADB_DATABASE']);
            $isMySQL = isset($envVars['MYSQL_ROOT_PASSWORD']) || isset($envVars['MYSQL_DATABASE']);

            if (! $isMariaDB && ! $isMySQL) {
                $this->dispatch('error', 'Could not determine database type. Make sure this is a MySQL or MariaDB container.');
                $this->showImportDatabaseDialog = false;

                return;
            }

            $rootPassword = $isMariaDB ? ($envVars['MARIADB_ROOT_PASSWORD'] ?? '') : ($envVars['MYSQL_ROOT_PASSWORD'] ?? '');
            $database = $isMariaDB ? ($envVars['MARIADB_DATABASE'] ?? '') : ($envVars['MYSQL_DATABASE'] ?? '');

            if (empty($rootPassword)) {
                $this->dispatch('error', 'Root password not found in container environment variables.');
                $this->showImportDatabaseDialog = false;

                return;
            }

            // Build import command
            $dbCommand = $isMariaDB ? 'mariadb' : 'mysql';
            $passwordVar = $isMariaDB ? 'MARIADB_ROOT_PASSWORD' : 'MYSQL_ROOT_PASSWORD';
            $databaseVar = $isMariaDB ? 'MARIADB_DATABASE' : 'MYSQL_DATABASE';

            // Validate file extension - only .sql files allowed
            if (! str_ends_with(strtolower($this->importDatabaseFile), '.sql')) {
                $this->dispatch('error', 'Only .sql files are allowed for database import.');
                $this->showImportDatabaseDialog = false;
                return;
            }

            // Build the import command using environment variables properly
            // Escape password and database name for shell
            $escapedPassword = str_replace("'", "'\\''", $rootPassword);
            $escapedDatabaseName = ! empty($database) ? str_replace("'", "'\\''", $database) : '';

            // Build command parts
            $commandParts = [];

            // Set environment variables (using single quotes to avoid expansion issues)
            $commandParts[] = "export {$passwordVar}='{$escapedPassword}'";
            if (! empty($database)) {
                $commandParts[] = "export {$databaseVar}='{$escapedDatabaseName}'";
            }

            // Build the pipe command - only .sql files, no compression
            $commandParts[] = "cat {$escapedPath}";

            // Build mysql/mariadb command with password
            // Use --password= format instead of -p to avoid issues with variable expansion
            if (! empty($database)) {
                $commandParts[] = "{$dbCommand} -u root --password=\${$passwordVar} \${$databaseVar}";
            } else {
                $commandParts[] = "{$dbCommand} -u root --password=\${$passwordVar}";
            }

            // Join with pipes and wrap in sh -c
            $fullCommand = implode(' | ', $commandParts);
            $importCommand = "docker exec {$escapedContainer} sh -c ".escapeshellarg($fullCommand);

            if ($server->isNonRoot()) {
                $importCommand = "sudo {$importCommand}";
            }

            // Execute import and capture both stdout and stderr
            $output = instant_remote_process([$importCommand], $server, false);

            // Check if command failed (output might contain error messages)
            if ($output === false || (is_string($output) && str_contains(strtolower($output), 'error'))) {
                throw new \Exception('Import command failed: '.($output ?: 'Unknown error'));
            }

            $this->importDatabaseFile = null;
            $this->showImportDatabaseDialog = false;
            $this->dispatch('success', 'Database imported successfully.');

            // Try to detect WordPress prefix after import
            $this->detectWordPressPrefixAfterImport($server, $containerName, $database);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to import database: '.$e->getMessage());
            $this->showImportDatabaseDialog = false;
        }
    }

    private function detectWordPressPrefixAfterImport($server, string $containerName, string $database): void
    {
        try {
            // Check if this is a WordPress container
            $escapedContainer = escapeshellarg($containerName);
            $checkWpConfig = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/wp-config.php && echo found || echo notfound'";
            if ($server->isNonRoot()) {
                $checkWpConfig = "sudo {$checkWpConfig}";
            }
            $wpConfigExists = trim(instant_remote_process([$checkWpConfig], $server, false) ?? '');

            if ($wpConfigExists !== 'found') {
                return; // Not a WordPress container
            }

            // Get environment variables
            $envCommand = "docker exec {$escapedContainer} env";
            if ($server->isNonRoot()) {
                $envCommand = "sudo {$envCommand}";
            }
            $envOutput = instant_remote_process([$envCommand], $server, false) ?? '';
            $envVars = [];
            foreach (explode("\n", $envOutput) as $line) {
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[$key] = $value;
                }
            }

            // Determine database type
            $isMariaDB = isset($envVars['MARIADB_ROOT_PASSWORD']) || isset($envVars['MARIADB_DATABASE']);
            $isMySQL = isset($envVars['MYSQL_ROOT_PASSWORD']) || isset($envVars['MYSQL_DATABASE']);

            if (! $isMariaDB && ! $isMySQL) {
                return;
            }

            $rootPassword = $isMariaDB ? ($envVars['MARIADB_ROOT_PASSWORD'] ?? '') : ($envVars['MYSQL_ROOT_PASSWORD'] ?? '');
            if (empty($rootPassword)) {
                return;
            }

            $dbCommand = $isMariaDB ? 'mariadb' : 'mysql';
            $passwordVar = $isMariaDB ? 'MARIADB_ROOT_PASSWORD' : 'MYSQL_ROOT_PASSWORD';
            $escapedPassword = str_replace("'", "'\\''", $rootPassword);
            $escapedDatabase = escapeshellarg($database);

            // Get list of tables
            $tablesCommand = "docker exec {$escapedContainer} sh -c 'export {$passwordVar}=\"{$escapedPassword}\" && {$dbCommand} -u root --password=\${$passwordVar} {$escapedDatabase} -e \"SHOW TABLES;\" 2>&1'";
            if ($server->isNonRoot()) {
                $tablesCommand = "sudo {$tablesCommand}";
            }

            $tablesOutput = instant_remote_process([$tablesCommand], $server, false);
            if (empty($tablesOutput)) {
                return;
            }

            // Look for WordPress core tables to detect prefix
            $wpCoreTables = ['posts', 'users', 'options', 'comments', 'terms', 'postmeta'];
            $lines = explode("\n", trim($tablesOutput));

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || stripos($line, 'tables_in_') === 0) {
                    continue;
                }

                foreach ($wpCoreTables as $coreTable) {
                    if (str_ends_with(strtolower($line), $coreTable)) {
                        $prefix = substr($line, 0, -strlen($coreTable));
                        if (! empty($prefix)) {
                            // Update wp-config.php with detected prefix
                            $escapedPrefix = escapeshellarg($prefix);
                            $updateCommand = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && sed -i \"s/\\\$table_prefix.*=.*['\\\"][^'\\\"]*['\\\"]/\\\$table_prefix = {$escapedPrefix}/\" wp-config.php 2>&1 || true'";
                            if ($server->isNonRoot()) {
                                $updateCommand = "sudo {$updateCommand}";
                            }
                            instant_remote_process([$updateCommand], $server, false);

                            $this->dispatch('success', "WordPress prefix detectado automáticamente: {$prefix}");
                            return;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently fail - prefix detection is optional
            \Log::debug('Failed to detect WordPress prefix after import', ['error' => $e->getMessage()]);
        }
    }

    public function showCreateFolderDialog()
    {
        $this->showCreateFolder = true;
    }

    public function hideCreateFolderDialog()
    {
        $this->showCreateFolder = false;
        $this->newFolderName = null;
    }

    public function openMoveDialog(string $path)
    {
        $normalizedPath = $this->normalizePathArgument($path);
        if ($normalizedPath === '') {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        $this->moveSource = $normalizedPath;
        $this->showMoveDialog = true;
    }

    public function closeMoveDialog()
    {
        $this->showMoveDialog = false;
        $this->moveSource = null;
        $this->moveDestination = null;
    }

    public function openRenameDialog(string $path)
    {
        $normalizedPath = $this->normalizePathArgument($path);
        if ($normalizedPath === '') {
            $this->dispatch('error', 'Invalid file path.');

            return;
        }

        $this->renameSource = $normalizedPath;
        $this->renameNewName = basename($normalizedPath);
        $this->showRenameDialog = true;
    }

    public function closeRenameDialog()
    {
        $this->showRenameDialog = false;
        $this->renameSource = null;
        $this->renameNewName = null;
    }

    public function renameFile()
    {
        $sourcePath = $this->normalizePathArgument((string) $this->renameSource);
        $newName = trim($this->renameNewName ?? '');

        if (empty($sourcePath) || empty($newName)) {
            $this->dispatch('error', 'Source path and new name are required.');

            return;
        }

        // Validar que el nuevo nombre no contenga caracteres peligrosos
        if (preg_match('/[\/\\\x00]/', $newName)) {
            $this->dispatch('error', 'Invalid characters in file name. Cannot contain /, \\, or null bytes.');

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
            $escapedSource = escapeshellarg($sourcePath);
            $escapedNewName = escapeshellarg($newName);

            // Obtener el directorio padre del archivo original
            $parentDir = dirname($sourcePath);
            if ($parentDir === '.' || $parentDir === '') {
                $parentDir = '/';
            }
            $escapedParentDir = escapeshellarg($parentDir);

            // Construir la ruta de destino
            $destinationPath = rtrim($parentDir, '/').'/'.$newName;
            $escapedDest = escapeshellarg($destinationPath);

            // Verificar si el destino ya existe
            $checkCommand = "docker exec {$escapedContainer} sh -c 'test -e {$escapedDest} && echo exists || echo notexists'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $checkResult = trim(instant_remote_process([$checkCommand], $server, false) ?? '');

            if ($checkResult === 'exists') {
                $this->dispatch('error', 'A file or folder with that name already exists.');

                return;
            }

            // Ejecutar el comando de renombrar (mv)
            $command = "docker exec {$escapedContainer} sh -c 'mv {$escapedSource} {$escapedDest}'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            instant_remote_process([$command], $server);

            // Si el archivo renombrado estaba abierto, actualizar la referencia
            if ($this->selectedFile === $sourcePath) {
                // Verificar si el destino es un directorio
                $isDirCommand = "docker exec {$escapedContainer} sh -c 'test -d {$escapedDest} && echo isdir || echo isfile'";
                if ($server->isNonRoot()) {
                    $isDirCommand = "sudo {$isDirCommand}";
                }
                $isDirResult = trim(instant_remote_process([$isDirCommand], $server, false) ?? '');

                if ($isDirResult === 'isdir') {
                    // Si es un directorio, cerrar el archivo
                    $this->selectedFile = null;
                    $this->fileContent = null;
                } else {
                    // Si es un archivo, actualizar la referencia y recargar el contenido
                    $this->selectedFile = $destinationPath;
                    $this->loadFileContent($destinationPath);
                }
            }

            // Si estaba en los archivos seleccionados, actualizar la lista
            if (in_array($sourcePath, $this->selectedFiles)) {
                $this->selectedFiles = array_map(function ($path) use ($sourcePath, $destinationPath) {
                    return $path === $sourcePath ? $destinationPath : $path;
                }, $this->selectedFiles);
            }

            $this->renameSource = null;
            $this->renameNewName = null;
            $this->showRenameDialog = false;
            $this->dispatch('success', 'File renamed successfully.');
            $this->loadFiles();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to rename file: '.$e->getMessage());
        }
    }

    public function cancelEditing()
    {
        $this->isEditing = false;
        if ($this->selectedFile) {
            $this->loadFileContent($this->selectedFile);
        }
    }

    public function closeFile()
    {
        $this->selectedFile = null;
        $this->fileContent = null;
        $this->isEditing = false;
    }

    public function hideImportDatabaseDialog()
    {
        $this->showImportDatabaseDialog = false;
        $this->importDatabaseFile = null;
        $this->importDatabaseContainer = null;
    }

    public function getDatabaseContainers()
    {
        $databaseContainers = [];

        foreach ($this->containers as $container) {
            $containerName = data_get($container, 'container.Names', '');
            $server = data_get($container, 'server');

            $isDb = false;

            // Check by container name
            if (str_contains(strtolower($containerName), 'mysql') || str_contains(strtolower($containerName), 'mariadb')) {
                $isDb = true;
            }

            // Check by container image
            if (! $isDb && $server) {
                try {
                    $escapedContainer = escapeshellarg($containerName);
                    $command = "docker inspect {$escapedContainer} --format='{{.Config.Image}}'";
                    if ($server->isNonRoot()) {
                        $command = "sudo {$command}";
                    }
                    $image = trim(instant_remote_process([$command], $server, false) ?? '');
                    if (str_contains(strtolower($image), 'mysql') || str_contains(strtolower($image), 'mariadb')) {
                        $isDb = true;
                    }
                } catch (\Throwable $e) {
                    // Ignore error, continue checking
                }
            }

            // Check by environment variables
            if (! $isDb && $server) {
                try {
                    $escapedContainer = escapeshellarg($containerName);
                    $command = "docker exec {$escapedContainer} env";
                    if ($server->isNonRoot()) {
                        $command = "sudo {$command}";
                    }
                    $envOutput = instant_remote_process([$command], $server, false) ?? '';
                    if (str_contains($envOutput, 'MYSQL_ROOT_PASSWORD') || str_contains($envOutput, 'MARIADB_ROOT_PASSWORD') ||
                        str_contains($envOutput, 'WORDPRESS_DB_HOST=mysql') || str_contains($envOutput, 'WORDPRESS_DB_HOST=mariadb')) {
                        $isDb = true;
                    }
                } catch (\Throwable $e) {
                    // Ignore error
                }
            }

            // Check for mysql/mariadb commands availability
            if (! $isDb && $server) {
                try {
                    $escapedContainer = escapeshellarg($containerName);
                    $command = "docker exec {$escapedContainer} sh -c 'command -v mysql || command -v mariadb'";
                    if ($server->isNonRoot()) {
                        $command = "sudo {$command}";
                    }
                    $cmdOutput = trim(instant_remote_process([$command], $server, false) ?? '');
                    if (! empty($cmdOutput)) {
                        $isDb = true;
                    }
                } catch (\Throwable $e) {
                    // Ignore error
                }
            }

            if ($isDb) {
                $databaseContainers[] = [
                    'name' => $containerName,
                    'server' => $server->name ?? 'Unknown',
                ];
            }
        }

        return $databaseContainers;
    }

    public function hideCompressDialog()
    {
        $this->showCompressDialog = false;
        $this->compressArchiveName = null;
        $this->overwriteExisting = false;
    }

    public function openQuickEdit(string $filename)
    {
        // First check if file is in current directory (from files list)
        $fileInCurrentDir = collect($this->files)->firstWhere('name', $filename);
        if ($fileInCurrentDir) {
            $this->openFile($fileInCurrentDir['path']);

            return;
        }

        // Common paths for wp-config.php and .env
        $commonPaths = [
            '/var/www/html/'.$filename,
            '/app/'.$filename,
            '/var/www/'.$filename,
            '/'.$filename,
        ];

        // Also check current directory
        if ($this->currentPath !== '/') {
            $commonPaths[] = rtrim($this->currentPath, '/').'/'.$filename;
        } else {
            $commonPaths[] = '/'.$filename;
        }

        // Additional WordPress-specific paths
        if ($filename === 'wp-config.php') {
            $commonPaths[] = '/var/www/html/wp-config.php';
            $commonPaths[] = '/app/wp-config.php';
            $commonPaths[] = '/wordpress/wp-config.php';
        }

        // Additional .env paths
        if ($filename === '.env') {
            $commonPaths[] = '/var/www/html/.env';
            $commonPaths[] = '/app/.env';
            $commonPaths[] = '/.env';
        }

        foreach ($commonPaths as $path) {
            try {
                $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
                if (is_null($container)) {
                    continue;
                }

                $server = data_get($container, 'server');
                $containerName = data_get($container, 'container.Names');
                $escapedContainer = escapeshellarg($containerName);
                $escapedPath = escapeshellarg($path);

                // Check if file exists (including hidden files)
                $checkCommand = "docker exec {$escapedContainer} sh -c 'test -f {$escapedPath} && echo exists || echo not_exists'";
                if ($server->isNonRoot()) {
                    $checkCommand = "sudo {$checkCommand}";
                }
                $exists = trim(instant_remote_process([$checkCommand], $server, false) ?? '') === 'exists';

                if ($exists) {
                    $this->openFile($path);

                    return;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        $this->dispatch('error', "File {$filename} not found in common locations. Make sure you're in the correct directory or the file exists.");
    }

    public function getDownloadUrl(string $path): string
    {
        if (empty($path)) {
            return '#';
        }

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

    public function openDatabasePanel()
    {
        // Si es una base de datos, verificar si tiene phpMyAdmin integrado
        if ($this->type === 'database' &&
            ($this->resource instanceof \App\Models\StandaloneMariadb ||
             $this->resource instanceof \App\Models\StandaloneMysql)) {

            $database = $this->resource;
            $containerName = $database->uuid.'-phpmyadmin';
            $server = $database->destination->server;

            // Verificar si el contenedor phpMyAdmin existe
            if ($server) {
                $escapedContainer = escapeshellarg($containerName);
                $checkCommand = "docker ps -a --filter name=^{$escapedContainer}$ --format '{{.Names}}'";
                if ($server->isNonRoot()) {
                    $checkCommand = "sudo {$checkCommand}";
                }

                $containerExists = instant_remote_process([$checkCommand], $server, false);
                if (!empty(trim($containerExists))) {
                    // phpMyAdmin está integrado, obtener URL y credenciales
                    $phpMyAdminUrl = $this->getPhpMyAdminUrlForDatabase($database);
                    if ($phpMyAdminUrl) {
                        $dbCredentials = $this->getDatabaseCredentialsForIntegratedPhpMyAdmin($database);

                        if ($dbCredentials) {
                            // Crear URL encriptada para el autologin
                            $encryptedData = null;
                            try {
                                $dataToEncrypt = json_encode([
                                    'url' => $phpMyAdminUrl,
                                    'credentials' => $dbCredentials,
                                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                                $encryptedData = \Illuminate\Support\Facades\Crypt::encryptString($dataToEncrypt);
                                session(['phpmyadmin_data' => $encryptedData]);
                            } catch (\Throwable $e) {
                                \Log::error('Error encrypting phpMyAdmin data: '.$e->getMessage());
                                session(['phpmyadmin_data_plain' => [
                                    'url' => $phpMyAdminUrl,
                                    'credentials' => $dbCredentials,
                                ]]);
                            }

                            $this->dispatch('openPhpMyAdmin',
                                url: $phpMyAdminUrl,
                                credentials: $dbCredentials,
                                encryptedData: $encryptedData
                            );

                            return;
                        }
                    }
                }
            }
        }

        // Buscar servicio phpMyAdmin en el mismo entorno (funciona para todos los contenedores)
        $phpMyAdminService = $this->findPhpMyAdminService();

        if ($phpMyAdminService) {
            // Obtener la URL del servicio phpMyAdmin
            $phpMyAdminUrl = $this->getPhpMyAdminUrl($phpMyAdminService);
            if ($phpMyAdminUrl) {
                // Obtener credenciales de la base de datos para autocompletar el formulario
                $dbCredentials = $this->getDatabaseCredentials($phpMyAdminService->environment, $phpMyAdminService);

                // Intentar configurar autenticación automática en phpMyAdmin
                $this->configurePhpMyAdminAutoLogin($phpMyAdminService, $dbCredentials);

                $this->phpMyAdminUrl = $phpMyAdminUrl;

                // Crear URL encriptada para el autologin
                $encryptedData = null;
                if ($dbCredentials) {
                    try {
                        $dataToEncrypt = json_encode([
                            'url' => $phpMyAdminUrl,
                            'credentials' => $dbCredentials,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                        // Intentar cifrar los datos
                        $encryptedData = \Illuminate\Support\Facades\Crypt::encryptString($dataToEncrypt);

                        // También guardar en sesión como respaldo
                        session(['phpmyadmin_data' => $encryptedData]);
                    } catch (\Throwable $e) {
                        // Si falla el cifrado, guardar en sesión sin cifrar (menos seguro pero funcional)
                        \Log::error('Error encrypting phpMyAdmin data: '.$e->getMessage());
                        session(['phpmyadmin_data_plain' => [
                            'url' => $phpMyAdminUrl,
                            'credentials' => $dbCredentials,
                        ]]);
                    }
                }

                // Disparar evento para abrir phpMyAdmin en nueva ventana con credenciales
                // En Livewire 3, pasar los datos como parámetros nombrados
                $this->dispatch('openPhpMyAdmin',
                    url: $phpMyAdminUrl,
                    credentials: $dbCredentials,
                    encryptedData: $encryptedData
                );

            return;
            }
        }

        // Si no se encuentra phpMyAdmin, mostrar mensaje
        $this->dispatch('error', 'phpMyAdmin service not found in this environment. Please add phpMyAdmin as a service to use it for database management.');
    }

    private function findDatabasesInEnvironment(): \Illuminate\Support\Collection
    {
        $databases = collect();

        try {
            // Obtener el entorno actual
            $environment = null;
            if ($this->type === 'database' &&
                ($this->resource instanceof \App\Models\StandaloneMariadb ||
                 $this->resource instanceof \App\Models\StandaloneMysql)) {
                // Si ya es una base de datos, usarla directamente
                $databases->push($this->resource);
                return $databases;
            } elseif ($this->type === 'service' && $this->resource instanceof \App\Models\Service) {
                $environment = $this->resource->environment;
            } elseif ($this->type === 'application' && $this->resource instanceof \App\Models\Application) {
                $environment = $this->resource->environment;
            }

            if ($environment) {
                // Buscar todas las bases de datos MySQL/MariaDB en el entorno
                $mariadbDatabases = \App\Models\StandaloneMariadb::whereHas('destination', function ($query) use ($environment) {
                    $query->where('environment_id', $environment->id);
                })->get();

                $mysqlDatabases = \App\Models\StandaloneMysql::whereHas('destination', function ($query) use ($environment) {
                    $query->where('environment_id', $environment->id);
                })->get();

                $databases = $mariadbDatabases->merge($mysqlDatabases);
            }
        } catch (\Throwable $e) {
            \Log::error('Error finding databases in environment: '.$e->getMessage());
        }

        return $databases;
    }

    private function getPhpMyAdminUrlForDatabase($database): ?string
    {
        try {
            $server = $database->destination->server;
            $containerName = $database->uuid.'-phpmyadmin';

            // Verificar si el contenedor phpMyAdmin existe
            $escapedContainer = escapeshellarg($containerName);
            $checkCommand = "docker ps -a --filter name=^{$escapedContainer}$ --format '{{.Names}}'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }

            $containerExists = instant_remote_process([$checkCommand], $server, false);
            if (empty(trim($containerExists))) {
                // El contenedor no existe aún, puede que necesite reiniciarse la base de datos
                return null;
            }

            // Buscar ServiceApplication para phpMyAdmin si está en un servicio
            // Primero intentar obtener desde variables de entorno del servicio
            $environment = $database->environment;
            if ($environment) {
                // Buscar servicios que puedan tener phpMyAdmin
                $services = $environment->services()->get();
                foreach ($services as $service) {
                    // Buscar aplicación phpMyAdmin en el servicio
                    foreach ($service->applications as $app) {
                        $appName = str($app->name)->lower();
                        $imageName = str($app->image)->lower();
                        if ($appName->contains('phpmyadmin') || $imageName->contains('phpmyadmin')) {
                            if ($app->fqdn) {
                                $fqdns = $app->fqdns;
                                if (!empty($fqdns)) {
                                    return $fqdns[0];
                                }
                            }

                            // Buscar en variables de entorno
                            $envVar = $service->environment_variables()
                                ->where(function ($query) {
                                    $query->where('key', 'like', 'SERVICE_URL_%PHPMYADMIN%')
                                        ->orWhere('key', 'like', 'SERVICE_FQDN_%PHPMYADMIN%');
                                })
                                ->first();

                            if ($envVar && $envVar->real_value) {
                                return $envVar->real_value;
                            }
                        }
                    }
                }
            }

            // Obtener la URL desde las variables de entorno del contenedor
            $envCommand = "docker exec {$escapedContainer} env 2>/dev/null | grep PMA_ABSOLUTE_URI | cut -d'=' -f2";
            if ($server->isNonRoot()) {
                $envCommand = "sudo {$envCommand}";
            }

            $url = trim(instant_remote_process([$envCommand], $server, false) ?? '');
            if (!empty($url)) {
                return $url;
            }

            // Si no se encuentra en las variables de entorno, generar una URL basada en el servidor
            // Usar un nombre más corto para evitar URLs muy largas
            $phpmyadminRandom = substr($database->uuid, 0, 8).'-phpmyadmin';
            $url = generateUrl($server, $phpmyadminRandom);

            return $url;
        } catch (\Throwable $e) {
            \Log::error('Error getting phpMyAdmin URL for database: '.$e->getMessage());
            return null;
        }
    }

    private function getDatabaseCredentialsForIntegratedPhpMyAdmin($database): ?array
    {
        try {
            $containerName = $database->uuid;
            $server = $database->destination->server;

            // Obtener credenciales desde la base de datos
            $rootPassword = null;
            if ($database instanceof \App\Models\StandaloneMariadb) {
                $rootPassword = $database->mariadb_root_password;
            } elseif ($database instanceof \App\Models\StandaloneMysql) {
                $rootPassword = $database->mysql_root_password;
            }

            if (!$rootPassword) {
                return null;
            }

            // phpMyAdmin está en el mismo docker-compose que la base de datos
            // En docker-compose, los servicios se comunican por nombre de servicio
            // El nombre del servicio en docker-compose es el UUID de la base de datos
            // phpMyAdmin puede conectarse usando este nombre directamente
            // Usamos el UUID completo como nombre del servidor
            return [
                'username' => 'root',
                'password' => $rootPassword,
                'server' => $containerName, // UUID de la base de datos (nombre del servicio en docker-compose)
            ];
        } catch (\Throwable $e) {
            \Log::error('Error getting database credentials for integrated phpMyAdmin: '.$e->getMessage());
            return null;
        }
    }

    private function configurePhpMyAdminAutoLogin(\App\Models\Service $phpMyAdminService, ?array $dbCredentials): void
    {
        if (! $dbCredentials) {
            return;
        }

        try {
            // Agregar variables de entorno para autenticación automática en phpMyAdmin
            // La imagen de linuxserver/phpmyadmin soporta estas variables cuando PMA_ARBITRARY=1
            // Sin embargo, estas variables no funcionan directamente para autenticación automática
            // Por eso usamos JavaScript para autocompletar el formulario

            // Nota: Las variables PMA_USER, PMA_PASSWORD y PMA_HOST no proporcionan
            // autenticación automática en la imagen de linuxserver/phpmyadmin.
            // El autocompletado se maneja mediante JavaScript en el frontend.
        } catch (\Throwable $e) {
            // Ignorar errores
        }
    }

    private function findPhpMyAdminService(): ?\App\Models\Service
    {
        try {
            // Obtener el entorno actual desde el recurso
            $environment = null;
            if ($this->type === 'service' && $this->resource instanceof \App\Models\Service) {
                $environment = $this->resource->environment;
            } elseif ($this->type === 'application' && $this->resource instanceof \App\Models\Application) {
                $environment = $this->resource->environment;
            } elseif ($this->type === 'database') {
                // Para bases de datos standalone, obtener el entorno desde el recurso
                if (method_exists($this->resource, 'environment')) {
                    $environment = $this->resource->environment;
                }

                // Si es una base de datos, verificar si tiene phpMyAdmin integrado en su docker-compose
                if ($this->resource instanceof \App\Models\StandaloneMariadb ||
                    $this->resource instanceof \App\Models\StandaloneMysql) {
                    // Verificar si la base de datos tiene phpMyAdmin en su configuración
                    // Las bases de datos ahora tienen phpMyAdmin integrado, así que crear un servicio virtual
                    // o buscar el contenedor phpMyAdmin directamente
                    $containerName = $this->resource->uuid.'-phpmyadmin';

                    // Verificar si el contenedor existe
                    $server = $this->resource->destination->server;
                    if ($server) {
                        $escapedContainer = escapeshellarg($containerName);
                        $checkCommand = "docker ps -a --filter name=^{$escapedContainer}$ --format '{{.Names}}'";
                        if ($server->isNonRoot()) {
                            $checkCommand = "sudo {$checkCommand}";
                        }

                        $containerExists = instant_remote_process([$checkCommand], $server, false);
                        if (!empty(trim($containerExists))) {
                            // El contenedor existe, retornar un servicio virtual
                            // Necesitamos obtener la URL desde las variables de entorno o generar una
                            return $this->createVirtualPhpMyAdminService($this->resource);
                        }
                    }
                }
            }

            if (! $environment) {
                return null;
            }

            // Buscar todos los servicios en el entorno
            $allServices = $environment->services()->get();

            // Buscar por nombre de servicio, imagen o docker_compose
            foreach ($allServices as $service) {
                $serviceName = str($service->name)->lower();

                // Verificar nombre del servicio
                if ($serviceName->contains('phpmyadmin')) {
                    return $service;
                }

                // Verificar en las aplicaciones del servicio
                foreach ($service->applications as $app) {
                    $appName = str($app->name)->lower();
                    $imageName = str($app->image)->lower();
                    if ($appName->contains('phpmyadmin') || $imageName->contains('phpmyadmin')) {
                        return $service;
                    }
                }

                // Verificar en docker_compose_raw por servicios phpmyadmin
                if ($service->docker_compose_raw) {
                    try {
                        $dockerCompose = \Symfony\Component\Yaml\Yaml::parse($service->docker_compose_raw);
                        $services = data_get($dockerCompose, 'services', []);
                        foreach ($services as $serviceNameInCompose => $serviceConfig) {
                            $serviceNameLower = str($serviceNameInCompose)->lower();
                            $image = str(data_get($serviceConfig, 'image', ''))->lower();

                            if ($serviceNameLower->contains('phpmyadmin') || $image->contains('phpmyadmin')) {
                                return $service;
                            }
                        }
                    } catch (\Throwable) {
                        // Ignorar errores de parsing
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getPhpMyAdminUrl(\App\Models\Service $service): ?string
    {
        try {
            // Buscar la aplicación phpMyAdmin en el servicio
            $phpMyAdminApp = null;

            foreach ($service->applications as $app) {
                $appName = str($app->name)->lower();
                $imageName = str($app->image)->lower();

                if ($appName->contains('phpmyadmin') || $imageName->contains('phpmyadmin')) {
                    $phpMyAdminApp = $app;
                    break;
                }
            }

            // Si no se encuentra específicamente phpMyAdmin, usar la primera aplicación del servicio
            if (! $phpMyAdminApp) {
                $phpMyAdminApp = $service->applications()->first();
            }

            if (! $phpMyAdminApp) {
                return null;
            }

            $baseUrl = null;

            // Intentar obtener FQDN de la aplicación
            if ($phpMyAdminApp->fqdn) {
                $fqdns = $phpMyAdminApp->fqdns;
                if (! empty($fqdns)) {
                    $baseUrl = $fqdns[0];
                }
            }

            // Si no hay FQDN en la aplicación, intentar obtenerlo desde las variables de entorno
            if (! $baseUrl) {
                $envVar = $service->environment_variables()
                    ->where(function ($query) {
                        $query->where('key', 'like', 'SERVICE_URL_%PHPMYADMIN%')
                            ->orWhere('key', 'like', 'SERVICE_FQDN_%PHPMYADMIN%')
                            ->orWhere('key', 'like', 'SERVICE_URL_PHPMYADMIN')
                            ->orWhere('key', 'like', 'SERVICE_FQDN_PHPMYADMIN');
                    })
                    ->first();

                if ($envVar && $envVar->real_value) {
                    $baseUrl = $envVar->real_value;
                }
            }

            if (! $baseUrl) {
                return null;
            }

            // Asegurar que tenga esquema http/https
            if (! str_starts_with($baseUrl, 'http://') && ! str_starts_with($baseUrl, 'https://')) {
                $baseUrl = 'https://'.$baseUrl;
            }

            // Limpiar la URL base (sin parámetros GET ni index.php)
            // El endpoint de autologin se encargará de hacer el POST con las credenciales
            $url = \Spatie\Url\Url::fromString($baseUrl);
            $path = $url->getPath();

            // Remover index.php si está presente
            if (str_ends_with($path, 'index.php')) {
                $path = dirname($path);
            }
            $path = rtrim($path, '/');

            // Asegurar que termine con /
            if (empty($path) || $path === '/') {
                $path = '/';
            } else {
                $path .= '/';
            }

            $url = $url->withPath($path)->withoutQueryParameters();

            return $url->__toString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getDatabaseCredentials($environment, \App\Models\Service $phpMyAdminService): ?array
    {
        try {
            // Buscar contenedor de base de datos en el mismo servicio o entorno
            $dbContainer = null;
            $dbService = null;
            $rootPassword = null;

            // Primero, buscar en el mismo servicio de phpMyAdmin (puede tener MySQL/MariaDB incluido)
            if ($phpMyAdminService->docker_compose_raw) {
                try {
                    $dockerCompose = \Symfony\Component\Yaml\Yaml::parse($phpMyAdminService->docker_compose_raw);
                    $services = data_get($dockerCompose, 'services', []);

                    foreach ($services as $serviceNameInCompose => $serviceConfig) {
                        $image = str(data_get($serviceConfig, 'image', ''))->lower();
                        $serviceNameLower = str($serviceNameInCompose)->lower();

                        // Buscar MySQL o MariaDB (excluir phpmyadmin)
                        if (($image->contains('mysql') || $image->contains('mariadb') ||
                             $serviceNameLower->contains('mysql') || $serviceNameLower->contains('mariadb')) &&
                            ! $serviceNameLower->contains('phpmyadmin')) {
                            $dbService = $phpMyAdminService;
                            $dbContainer = $serviceNameInCompose;
                            break;
                        }
                    }
                } catch (\Throwable) {
                    // Ignorar errores de parsing
                }
            }

            // Si no se encuentra en el mismo servicio, buscar en otros servicios del entorno
            if (! $dbService) {
                $allServices = $environment->services()->get();

                foreach ($allServices as $service) {
                    // Saltar el servicio phpMyAdmin
                    if ($service->id === $phpMyAdminService->id) {
                        continue;
                    }

                    // Verificar en docker_compose_raw por servicios MySQL/MariaDB
                    if ($service->docker_compose_raw) {
                        try {
                            $dockerCompose = \Symfony\Component\Yaml\Yaml::parse($service->docker_compose_raw);
                            $services = data_get($dockerCompose, 'services', []);

                            foreach ($services as $serviceNameInCompose => $serviceConfig) {
                                $image = str(data_get($serviceConfig, 'image', ''))->lower();
                                $serviceNameLower = str($serviceNameInCompose)->lower();

                                // Buscar MySQL o MariaDB
                                if ($image->contains('mysql') || $image->contains('mariadb') ||
                                    $serviceNameLower->contains('mysql') || $serviceNameLower->contains('mariadb')) {
                                    $dbService = $service;
                                    $dbContainer = $serviceNameInCompose;
                                    break 2;
                                }
                            }
                        } catch (\Throwable) {
                            // Ignorar errores de parsing
                        }
                    }
                }
            }

            if (! $dbService || ! $dbContainer) {
                return null;
            }

            // Obtener credenciales desde las variables de entorno del servicio
            // Buscar primero en las variables de entorno del servicio de base de datos
            $rootPasswordVar = $dbService->environment_variables()
                ->where(function ($query) {
                    $query->where('key', 'MYSQL_ROOT_PASSWORD')
                        ->orWhere('key', 'MARIADB_ROOT_PASSWORD')
                        ->orWhere('key', 'SERVICE_PASSWORD_ROOT')
                        ->orWhere('key', 'SERVICE_PASSWORD_MYSQLROOT')
                        ->orWhere('key', 'SERVICE_PASSWORD_MARIADBROOT');
                })
                ->first();

            // Si no se encuentra, buscar también en las aplicaciones del servicio
            if (! $rootPasswordVar || ! $rootPasswordVar->real_value || str($rootPasswordVar->real_value)->startsWith('$')) {
                // Buscar en las aplicaciones de base de datos del servicio
                $dbApps = $dbService->databases()->get();
                foreach ($dbApps as $dbApp) {
                    if ($dbApp->mysql_root_password) {
                        $rootPassword = $dbApp->mysql_root_password;
                        $dbHost = $dbContainer;

                        return [
                            'username' => 'root',
                            'password' => $rootPassword,
                            'server' => $dbHost,
                        ];
                    }
                }

                // Si aún no se encuentra, intentar obtener desde el contenedor directamente
                if ($dbService->server) {
                    try {
                        $containerName = "{$dbContainer}-{$dbService->uuid}";
                        $server = $dbService->server;
                        $escapedContainer = escapeshellarg($containerName);

                        // Intentar obtener la contraseña desde las variables de entorno del contenedor
                        $envCommand = "docker exec {$escapedContainer} env 2>/dev/null | grep -E '(MYSQL_ROOT_PASSWORD|MARIADB_ROOT_PASSWORD)' | head -1";
                        if ($server->isNonRoot()) {
                            $envCommand = "sudo {$envCommand}";
                        }

                        $envOutput = instant_remote_process([$envCommand], $server, false);
                        if ($envOutput && str_contains($envOutput, '=')) {
                            $parts = explode('=', trim($envOutput), 2);
                            if (count($parts) === 2) {
                                $rootPassword = $parts[1];
                                $dbHost = $dbContainer;

                                return [
                                    'username' => 'root',
                                    'password' => $rootPassword,
                                    'server' => $dbHost,
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        // Ignorar errores
                    }
                }

                // Si aún no se encuentra, retornar null
                if (! $rootPasswordVar || ! $rootPasswordVar->real_value || str($rootPasswordVar->real_value)->startsWith('$')) {
                    \Log::warning('phpMyAdmin: Could not resolve database password', [
                        'dbService' => $dbService->id,
                        'dbContainer' => $dbContainer,
                    ]);
                    return null;
                }
            }

            // Obtener el valor real de la contraseña
            $rootPassword = $rootPasswordVar->real_value;

            // Si la contraseña aún empieza con $, intentar obtenerla del contenedor
            if (str($rootPassword)->startsWith('$')) {
                \Log::warning('phpMyAdmin: Password variable not resolved, trying container', [
                    'password_var' => $rootPassword,
                ]);

                // Ya intentamos obtenerla del contenedor arriba, si llegamos aquí es que falló
                return null;
            }

            // Obtener el nombre del host del contenedor para phpMyAdmin
            // phpMyAdmin se conecta desde dentro de su contenedor, así que necesita el nombre
            // que funcione dentro de la red Docker

            if ($dbService->id === $phpMyAdminService->id) {
                // Mismo servicio de Docker Compose: usar el nombre del servicio directamente
                // Dentro de Docker Compose, los servicios se comunican por nombre de servicio
                $dbHost = $dbContainer;
            } else {
                // Diferentes servicios: verificar si están conectados a la misma red Docker
                // Si están en la misma red, pueden usar el nombre del servicio con alias
                // Si no, necesitamos la IP interna del contenedor

                $dbHost = null;

                // Verificar si ambos servicios están en la misma red
                $phpMyAdminNetworks = collect($phpMyAdminService->networks())->pluck('name')->toArray();
                $dbServiceNetworks = collect($dbService->networks())->pluck('name')->toArray();
                $commonNetworks = array_intersect($phpMyAdminNetworks, $dbServiceNetworks);

                if (!empty($commonNetworks)) {
                    // Están en la misma red: intentar primero con solo el nombre del servicio
                    // Si eso no funciona, se puede probar con el nombre completo del contenedor
                    // Pero primero intentemos con el nombre simple del servicio (más común)
                    $dbHost = $dbContainer;

                    \Log::info('phpMyAdmin: Services in same network, using service name', [
                        'dbHost' => $dbHost,
                        'commonNetworks' => $commonNetworks,
                        'dbContainer' => $dbContainer,
                    ]);
                } else {
                    // No están en la misma red: obtener la IP interna del contenedor en la red de phpMyAdmin
                    try {
                        $dbContainerName = "{$dbContainer}-{$dbService->uuid}";
                        $phpMyAdminContainerName = null;

                        // Buscar el contenedor de phpMyAdmin
                        foreach ($phpMyAdminService->applications as $app) {
                            if (str($app->name)->lower()->contains('phpmyadmin') ||
                                str($app->image)->lower()->contains('phpmyadmin')) {
                                $phpMyAdminContainerName = "{$app->name}-{$phpMyAdminService->uuid}";
                                break;
                            }
                        }

                        // Si no encontramos el contenedor de phpMyAdmin, usar el primer contenedor del servicio
                        if (! $phpMyAdminContainerName) {
                            $firstApp = $phpMyAdminService->applications()->first();
                            if ($firstApp) {
                                $phpMyAdminContainerName = "{$firstApp->name}-{$phpMyAdminService->uuid}";
                            }
                        }

                        $server = $dbService->server;

                        if ($server && $phpMyAdminContainerName) {
                            // Obtener la red de phpMyAdmin
                            $escapedPhpMyAdmin = escapeshellarg($phpMyAdminContainerName);
                            $phpMyAdminNetworksCommand = "docker inspect --format='{{range \$key, \$value := .NetworkSettings.Networks}}{{\$key}} {{end}}' {$escapedPhpMyAdmin} 2>/dev/null";
                            if ($server->isNonRoot()) {
                                $phpMyAdminNetworksCommand = "sudo {$phpMyAdminNetworksCommand}";
                            }

                            $phpMyAdminNetworksOutput = trim(instant_remote_process([$phpMyAdminNetworksCommand], $server, false) ?? '');
                            $phpMyAdminNetworks = array_filter(explode(' ', $phpMyAdminNetworksOutput));

                            // Obtener las redes del contenedor de la base de datos
                            $escapedDbContainer = escapeshellarg($dbContainerName);
                            $dbNetworksCommand = "docker inspect --format='{{range \$key, \$value := .NetworkSettings.Networks}}{{\$key}} {{end}}' {$escapedDbContainer} 2>/dev/null";
                            if ($server->isNonRoot()) {
                                $dbNetworksCommand = "sudo {$dbNetworksCommand}";
                            }

                            $dbNetworksOutput = trim(instant_remote_process([$dbNetworksCommand], $server, false) ?? '');
                            $dbNetworks = array_filter(explode(' ', $dbNetworksOutput));

                            // Buscar una red común
                            $commonNetwork = array_intersect($phpMyAdminNetworks, $dbNetworks);

                            if (!empty($commonNetwork)) {
                                // Están en una red común: obtener la IP en esa red
                                $commonNetworkName = reset($commonNetwork);
                                $ipCommand = "docker inspect --format='{{index .NetworkSettings.Networks \"{$commonNetworkName}\" | .IPAddress}}' {$escapedDbContainer} 2>/dev/null";
                                if ($server->isNonRoot()) {
                                    $ipCommand = "sudo {$ipCommand}";
                                }

                                $containerIP = trim(instant_remote_process([$ipCommand], $server, false) ?? '');

                                if (!empty($containerIP) && filter_var($containerIP, FILTER_VALIDATE_IP)) {
                                    $dbHost = $containerIP;
                                    \Log::info('phpMyAdmin: Using container IP in common network', [
                                        'container' => $dbContainerName,
                                        'network' => $commonNetworkName,
                                        'ip' => $containerIP,
                                    ]);
                                }
                            } else {
                                // No hay red común: usar la primera IP disponible del contenedor de la base de datos
                                $ipCommand = "docker inspect --format='{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' {$escapedDbContainer} 2>/dev/null | head -1";
                                if ($server->isNonRoot()) {
                                    $ipCommand = "sudo {$ipCommand}";
                                }

                                $containerIP = trim(instant_remote_process([$ipCommand], $server, false) ?? '');

                                if (!empty($containerIP) && filter_var($containerIP, FILTER_VALIDATE_IP)) {
                                    $dbHost = $containerIP;
                                    \Log::info('phpMyAdmin: Using container IP (no common network)', [
                                        'container' => $dbContainerName,
                                        'ip' => $containerIP,
                                    ]);
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('phpMyAdmin: Could not get container IP', [
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Si no se pudo obtener la IP, usar el nombre completo del contenedor como último recurso
                    if (! $dbHost) {
                        $dbHost = "{$dbContainer}-{$dbService->uuid}";
                    }
                }
            }

            \Log::info('phpMyAdmin: Credentials obtained', [
                'username' => 'root',
                'server' => $dbHost,
                'password_length' => strlen($rootPassword),
            ]);

            return [
                'username' => 'root',
                'password' => $rootPassword,
                'server' => $dbHost,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }


    // Métodos del panel integrado de bases de datos - ya no se usan (reemplazado por phpMyAdmin)
    // Se mantienen comentados por si se necesitan en el futuro

    // public function closeDatabasePanel()
    // {
    //     $this->showDatabasePanel = false;
    //     $this->selectedDatabase = null;
    //     $this->tables = [];
    //     $this->selectedTable = null;
    //     $this->tableStructure = [];
    //     $this->tableData = [];
    //     $this->currentPage = 1;
    // }

    // Adminer integration removed - using phpMyAdmin instead

    public function loadDatabases()
    {
        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');

            // Get database credentials
            $escapedContainer = escapeshellarg($containerName);
            $command = "docker exec {$escapedContainer} env";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $envOutput = instant_remote_process([$command], $server, false) ?? '';

            // Determine database command (mysql or mariadb)
            $dbCommand = 'mysql';
            $checkCommand = "docker exec {$escapedContainer} sh -c 'command -v mysql >/dev/null 2>&1 && echo mysql || command -v mariadb >/dev/null 2>&1 && echo mariadb || echo notfound'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $dbCmdOutput = trim(instant_remote_process([$checkCommand], $server, false) ?? '');
            if ($dbCmdOutput === 'mariadb') {
                $dbCommand = 'mariadb';
            }

            // Get root password from environment
            $passwordVar = 'MYSQL_ROOT_PASSWORD';
            if (str_contains($envOutput, 'MARIADB_ROOT_PASSWORD')) {
                $passwordVar = 'MARIADB_ROOT_PASSWORD';
            }

            // Build command to list databases
            $listCommand = "docker exec {$escapedContainer} sh -c 'export {$passwordVar}=\${$passwordVar} && {$dbCommand} -u root --password=\${$passwordVar} -e \"SHOW DATABASES;\" 2>&1'";
            if ($server->isNonRoot()) {
                $listCommand = "sudo {$listCommand}";
            }

            $output = instant_remote_process([$listCommand], $server, false);

            if ($output === false || str_contains(strtolower($output), 'error') || str_contains(strtolower($output), 'access denied')) {
                throw new \Exception('Failed to connect to database: '.($output ?: 'Unknown error'));
            }

            // Parse database list (skip header line and system databases)
            $lines = explode("\n", trim($output));
            $databases = [];
            $skipDatabases = ['information_schema', 'performance_schema', 'mysql', 'sys'];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || in_array(strtolower($line), $skipDatabases)) {
                    continue;
                }
                // Skip header line
                if (strtolower($line) === 'database') {
                    continue;
                }
                $databases[] = $line;
            }

            $this->databases = $databases;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load databases: '.$e->getMessage());
            $this->databases = [];
        }
    }

    public function selectDatabase(string $database)
    {
        $this->selectedDatabase = $database;
        $this->selectedTable = null;
        $this->tableStructure = [];
        $this->tableData = [];
        $this->currentPage = 1;
        $this->loadTables();
    }

    public function loadTables()
    {
        if (empty($this->selectedDatabase)) {
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

            // Get database credentials
            $escapedContainer = escapeshellarg($containerName);
            $command = "docker exec {$escapedContainer} env";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $envOutput = instant_remote_process([$command], $server, false) ?? '';

            // Determine database command
            $dbCommand = 'mysql';
            $checkCommand = "docker exec {$escapedContainer} sh -c 'command -v mysql >/dev/null 2>&1 && echo mysql || command -v mariadb >/dev/null 2>&1 && echo mariadb || echo notfound'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $dbCmdOutput = trim(instant_remote_process([$checkCommand], $server, false) ?? '');
            if ($dbCmdOutput === 'mariadb') {
                $dbCommand = 'mariadb';
            }

            // Get root password
            $passwordVar = 'MYSQL_ROOT_PASSWORD';
            if (str_contains($envOutput, 'MARIADB_ROOT_PASSWORD')) {
                $passwordVar = 'MARIADB_ROOT_PASSWORD';
            }

            $escapedDatabase = escapeshellarg($this->selectedDatabase);

            // List tables
            $listCommand = "docker exec {$escapedContainer} sh -c 'export {$passwordVar}=\${$passwordVar} && {$dbCommand} -u root --password=\${$passwordVar} {$escapedDatabase} -e \"SHOW TABLES;\" 2>&1'";
            if ($server->isNonRoot()) {
                $listCommand = "sudo {$listCommand}";
            }

            $output = instant_remote_process([$listCommand], $server, false);

            if ($output === false || str_contains(strtolower($output), 'error')) {
                throw new \Exception('Failed to load tables: '.($output ?: 'Unknown error'));
            }

            // Parse table list
            $lines = explode("\n", trim($output));
            $tables = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                // Skip header line (usually "Tables_in_<database>")
                if (stripos($line, 'tables_in_') === 0) {
                    continue;
                }
                $tables[] = $line;
            }

            $this->tables = $tables;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load tables: '.$e->getMessage());
            $this->tables = [];
        }
    }

    public function selectTable(string $table)
    {
        $this->selectedTable = $table;
        $this->currentPage = 1;
        $this->loadTableStructure();
        $this->loadTableData();
    }

    public function loadTableStructure()
    {
        if (empty($this->selectedDatabase) || empty($this->selectedTable)) {
            return;
        }

        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');

            // Get database credentials
            $escapedContainer = escapeshellarg($containerName);
            $command = "docker exec {$escapedContainer} env";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $envOutput = instant_remote_process([$command], $server, false) ?? '';

            // Determine database command
            $dbCommand = 'mysql';
            $checkCommand = "docker exec {$escapedContainer} sh -c 'command -v mysql >/dev/null 2>&1 && echo mysql || command -v mariadb >/dev/null 2>&1 && echo mariadb || echo notfound'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $dbCmdOutput = trim(instant_remote_process([$checkCommand], $server, false) ?? '');
            if ($dbCmdOutput === 'mariadb') {
                $dbCommand = 'mariadb';
            }

            // Get root password
            $passwordVar = 'MYSQL_ROOT_PASSWORD';
            if (str_contains($envOutput, 'MARIADB_ROOT_PASSWORD')) {
                $passwordVar = 'MARIADB_ROOT_PASSWORD';
            }

            $escapedDatabase = escapeshellarg($this->selectedDatabase);
            $escapedTable = escapeshellarg($this->selectedTable);

            // Get table structure
            $structureCommand = "docker exec {$escapedContainer} sh -c 'export {$passwordVar}=\${$passwordVar} && {$dbCommand} -u root --password=\${$passwordVar} {$escapedDatabase} -e \"DESCRIBE {$escapedTable};\" 2>&1'";
            if ($server->isNonRoot()) {
                $structureCommand = "sudo {$structureCommand}";
            }

            $output = instant_remote_process([$structureCommand], $server, false);

            if ($output === false || str_contains(strtolower($output), 'error')) {
                $this->tableStructure = [];

                return;
            }

            // Parse structure (Field, Type, Null, Key, Default, Extra)
            $lines = explode("\n", trim($output));
            $structure = [];

            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line), 6);
                if (count($parts) >= 2) {
                    $structure[] = [
                        'field' => $parts[0] ?? '',
                        'type' => $parts[1] ?? '',
                        'null' => $parts[2] ?? '',
                        'key' => $parts[3] ?? '',
                        'default' => $parts[4] ?? '',
                        'extra' => $parts[5] ?? '',
                    ];
                }
            }

            $this->tableStructure = $structure;
        } catch (\Throwable $e) {
            $this->tableStructure = [];
        }
    }

    public function loadTableData()
    {
        if (empty($this->selectedDatabase) || empty($this->selectedTable)) {
            return;
        }

        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');

            // Get database credentials
            $escapedContainer = escapeshellarg($containerName);
            $command = "docker exec {$escapedContainer} env";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $envOutput = instant_remote_process([$command], $server, false) ?? '';

            // Determine database command
            $dbCommand = 'mysql';
            $checkCommand = "docker exec {$escapedContainer} sh -c 'command -v mysql >/dev/null 2>&1 && echo mysql || command -v mariadb >/dev/null 2>&1 && echo mariadb || echo notfound'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $dbCmdOutput = trim(instant_remote_process([$checkCommand], $server, false) ?? '');
            if ($dbCmdOutput === 'mariadb') {
                $dbCommand = 'mariadb';
            }

            // Get root password
            $passwordVar = 'MYSQL_ROOT_PASSWORD';
            if (str_contains($envOutput, 'MARIADB_ROOT_PASSWORD')) {
                $passwordVar = 'MARIADB_ROOT_PASSWORD';
            }

            $escapedDatabase = escapeshellarg($this->selectedDatabase);
            $escapedTable = escapeshellarg($this->selectedTable);

            // Get table data with limit - use tab-separated format for better parsing
            $offset = ($this->currentPage - 1) * $this->perPage;
            $dataCommand = "docker exec {$escapedContainer} sh -c 'export {$passwordVar}=\${$passwordVar} && {$dbCommand} -u root --password=\${$passwordVar} {$escapedDatabase} -e \"SELECT * FROM {$escapedTable} LIMIT {$this->perPage} OFFSET {$offset};\" 2>&1'";
            if ($server->isNonRoot()) {
                $dataCommand = "sudo {$dataCommand}";
            }

            $output = instant_remote_process([$dataCommand], $server, false);

            if ($output === false || str_contains(strtolower($output), 'error')) {
                $this->tableData = [];

                return;
            }

            // Parse table data (tab-separated values)
            $lines = explode("\n", trim($output));
            $data = [];
            $headers = null;

            foreach ($lines as $index => $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                // Split by tab, but handle cases where tabs might be escaped or missing
                $values = preg_split('/\t+/', $line);

                if ($index === 0) {
                    // First line should be headers
                    $headers = array_map('trim', $values);
                    continue;
                }

                // Match data rows to headers
                if ($headers && count($values) > 0) {
                    $row = [];
                    foreach ($headers as $i => $header) {
                        $row[$header] = $values[$i] ?? null;
                    }
                    $data[] = $row;
                }
            }

            $this->tableData = $data;
        } catch (\Throwable $e) {
            $this->tableData = [];
        }
    }

    public function nextPage()
    {
        $this->currentPage++;
        $this->loadTableData();
    }

    public function previousPage()
    {
        if ($this->currentPage > 1) {
            $this->currentPage--;
            $this->loadTableData();
        }
    }

    public function render()
    {
        return view('livewire.project.shared.file-explorer');
    }

    private function normalizePathArgument(string $path): string
    {
        $normalizedPath = trim($path);

        if (
            (str_starts_with($normalizedPath, "'") && str_ends_with($normalizedPath, "'")) ||
            (str_starts_with($normalizedPath, '"') && str_ends_with($normalizedPath, '"'))
        ) {
            $normalizedPath = substr($normalizedPath, 1, -1);
        }

        return trim($normalizedPath);
    }

    private function decodePathFromEncoded(string $encodedPath): ?string
    {
        $normalizedEncodedPath = trim($encodedPath);
        if (
            (str_starts_with($normalizedEncodedPath, "'") && str_ends_with($normalizedEncodedPath, "'")) ||
            (str_starts_with($normalizedEncodedPath, '"') && str_ends_with($normalizedEncodedPath, '"'))
        ) {
            $normalizedEncodedPath = substr($normalizedEncodedPath, 1, -1);
        }

        $normalizedEncodedPath = trim($normalizedEncodedPath);
        if ($normalizedEncodedPath === '') {
            return null;
        }

        $padded = str_pad(
            strtr($normalizedEncodedPath, '-_', '+/'),
            strlen($normalizedEncodedPath) % 4 === 0 ? strlen($normalizedEncodedPath) : strlen($normalizedEncodedPath) + (4 - (strlen($normalizedEncodedPath) % 4)),
            '=',
            STR_PAD_RIGHT
        );

        $decodedPath = base64_decode($padded, true);
        if ($decodedPath === false || $decodedPath === '') {
            return null;
        }

        return $decodedPath;
    }

    private function getSelectedContainerContext(): ?array
    {
        if ($this->selected_container === 'default') {
            return null;
        }

        $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
        if ($container === null) {
            return null;
        }

        $server = data_get($container, 'server');
        $containerName = data_get($container, 'container.Names');
        if (! $server instanceof Server || ! is_string($containerName) || $containerName === '') {
            return null;
        }

        return [
            'server' => $server,
            'escapedContainer' => escapeshellarg($containerName),
        ];
    }
}
