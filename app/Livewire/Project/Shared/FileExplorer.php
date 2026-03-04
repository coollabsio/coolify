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

    public $uploadFile = null;

    public ?string $moveSource = null;

    public ?string $moveDestination = null;

    public bool $showMoveDialog = false;

    public array $selectedFiles = [];

    public bool $showCompressDialog = false;

    public ?string $compressArchiveName = null;

    public bool $overwriteExisting = false;

    public bool $showImportDatabaseDialog = false;

    public ?string $importDatabaseFile = null;

    public ?string $importDatabaseContainer = null;

    public bool $isMySQLOrMariaDB = false;

    public bool $hasMySQLOrMariaDBContainer = false;

    public bool $showDatabasePanel = false;

    public array $databases = [];

    public ?string $selectedDatabase = null;

    public array $tables = [];

    public ?string $selectedTable = null;

    public array $tableStructure = [];

    public array $tableData = [];

    public int $currentPage = 1;

    public int $perPage = 50;

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
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            if ($this->resource->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->server);
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
            $this->checkDatabaseType();
            $this->checkForDatabaseContainers();
            $this->loadFiles();
        } else {
            $this->isMySQLOrMariaDB = false;
            $this->hasMySQLOrMariaDBContainer = false;
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
            $command = "docker exec {$escapedContainer} sh -c 'ls -lah {$escapedPath} 2>/dev/null | tail -n +2'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $output = instant_remote_process([$command], $server, false);

            $this->files = $this->parseFileList($output ?? '');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load files: '.$e->getMessage());
            $this->files = [];
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

    public function getFileLanguage(string $path): string
    {
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

            // Check if file is compressed
            $isCompressed = str_ends_with(strtolower($this->importDatabaseFile), '.gz') || 
                           str_ends_with(strtolower($this->importDatabaseFile), '.zip');

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
            
            // Build the pipe command
            if ($isCompressed && str_ends_with(strtolower($this->importDatabaseFile), '.gz')) {
                $commandParts[] = "gunzip -c {$escapedPath}";
            } elseif ($isCompressed && str_ends_with(strtolower($this->importDatabaseFile), '.zip')) {
                $commandParts[] = "unzip -p {$escapedPath}";
            } else {
                $commandParts[] = "cat {$escapedPath}";
            }
            
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
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to import database: '.$e->getMessage());
            $this->showImportDatabaseDialog = false;
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
        $this->moveSource = $path;
        $this->showMoveDialog = true;
    }

    public function closeMoveDialog()
    {
        $this->showMoveDialog = false;
        $this->moveSource = null;
        $this->moveDestination = null;
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

        $this->showDatabasePanel = true;
        $this->generateAdminerUrl();
    }


    public function closeDatabasePanel()
    {
        $this->showDatabasePanel = false;
        $this->selectedDatabase = null;
        $this->tables = [];
        $this->selectedTable = null;
        $this->tableStructure = [];
        $this->tableData = [];
        $this->currentPage = 1;
        $this->adminerUrl = null;
    }

    public ?string $adminerUrl = null;

    public function generateAdminerUrl()
    {
        try {
            $container = collect($this->containers)->firstWhere('container.Names', $this->selected_container);
            if (is_null($container)) {
                $this->adminerUrl = null;

                return;
            }

            $server = data_get($container, 'server');
            $containerName = data_get($container, 'container.Names');

            if (is_null($server)) {
                $this->adminerUrl = null;

                return;
            }

            // Determine route name based on type
            $routeName = match ($this->type) {
                'application' => 'project.application.adminer',
                'database' => 'project.database.adminer',
                'service' => 'project.service.adminer',
                default => 'project.database.adminer',
            };

            // Build route parameters array
            $routeParams = [];
            if (isset($this->parameters['project_uuid'])) {
                $routeParams['project_uuid'] = $this->parameters['project_uuid'];
            }
            if (isset($this->parameters['environment_uuid'])) {
                $routeParams['environment_uuid'] = $this->parameters['environment_uuid'];
            }
            if (isset($this->parameters['application_uuid'])) {
                $routeParams['application_uuid'] = $this->parameters['application_uuid'];
            }
            if (isset($this->parameters['database_uuid'])) {
                $routeParams['database_uuid'] = $this->parameters['database_uuid'];
            }
            if (isset($this->parameters['service_uuid'])) {
                $routeParams['service_uuid'] = $this->parameters['service_uuid'];
            }

            // Use Adminer proxy route that handles the connection securely
            // Pass container and server_id as query parameters
            try {
                $this->adminerUrl = route($routeName, $routeParams).'?container='.urlencode($containerName).'&server_id='.$server->id;
            } catch (\Exception $e) {
                // Fallback: use direct URL construction if route doesn't exist
                $baseUrl = url('/');
                $path = match ($this->type) {
                    'application' => "/project/{$routeParams['project_uuid']}/environment/{$routeParams['environment_uuid']}/application/{$routeParams['application_uuid']}/adminer",
                    'database' => "/project/{$routeParams['project_uuid']}/environment/{$routeParams['environment_uuid']}/database/{$routeParams['database_uuid']}/adminer",
                    'service' => "/project/{$routeParams['project_uuid']}/environment/{$routeParams['environment_uuid']}/service/{$routeParams['service_uuid']}/adminer",
                    default => "/project/{$routeParams['project_uuid']}/environment/{$routeParams['environment_uuid']}/database/{$routeParams['database_uuid']}/adminer",
                };
                $this->adminerUrl = $baseUrl.$path.'?container='.urlencode($containerName).'&server_id='.$server->id;
            }
        } catch (\Throwable $e) {
            $this->adminerUrl = null;
            $this->dispatch('error', 'Failed to generate database connection URL: '.$e->getMessage());
        }
    }

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
}
