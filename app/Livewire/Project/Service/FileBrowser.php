<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneMysql;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneRedis;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneClickhouse;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;

class FileBrowser extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public Service|StandalonePostgresql|StandaloneMysql|StandaloneMariadb|StandaloneMongodb|StandaloneRedis|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|Application $resource;
    public Server $server;
    public string $containerName = '';
    public string $currentPath = '/';
    public array $files = [];
    public bool $isLoading = false;
    public ?string $errorMessage = null;
    public ?string $successMessage = null;
    public array $parameters = [];
    
    // Upload
    public $uploadFile;
    public string $uploadPath = '';

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id')
                ->where('uuid', request()->route('project_uuid'))
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', request()->route('environment_uuid'))
                ->firstOrFail();
            $this->resource = $environment->services()->whereUuid(request()->route('service_uuid'))->firstOrFail();
            
            $this->authorize('update', $this->resource);
            
            // Get server and container info
            if ($this->resource instanceof Service) {
                $this->server = $this->resource->server;
                // Get first container name from docker compose
                $this->containerName = $this->getContainerName();
            } else {
                $this->server = $this->resource->destination->server;
                $this->containerName = $this->resource->uuid;
            }
            
            $this->browsePath('/');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function getContainerName(): string
    {
        // Try to get container name from service
        $containers = data_get($this->resource, 'docker_compose', []);
        if (is_array($containers) && !empty($containers)) {
            return array_key_first($containers);
        }
        return '';
    }

    public function browsePath(string $path)
    {
        $this->isLoading = true;
        $this->errorMessage = null;
        $this->successMessage = null;
        $this->currentPath = $path;
        
        try {
            $this->files = $this->listFiles($path);
        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed to load files: ' . $e->getMessage();
            $this->files = [];
        } finally {
            $this->isLoading = false;
        }
    }

    private function listFiles(string $path): array
    {
        if (empty($this->containerName)) {
            throw new \Exception('Container name not found');
        }

        // Escape path for shell
        $escapedPath = escapeshellarg($path);
        
        // List files with details (type, permissions, size, name)
        $command = collect([
            "docker exec {$this->containerName} ls -la {$escapedPath} 2>&1"
        ]);
        
        $output = instant_remote_process($command, $this->server, false);
        
        if (!$output) {
            throw new \Exception('Failed to list files. Container may not be running.');
        }

        // Parse ls output
        $files = [];
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            // Skip total line
            if (str_starts_with($line, 'total')) continue;
            
            $parts = preg_split('/\s+/', trim($line), 9);
            if (count($parts) < 9) continue;
            
            $permissions = $parts[0];
            $size = $parts[4];
            $name = $parts[8];
            
            // Skip . and ..
            if ($name === '.' || $name === '..') continue;
            
            $isDirectory = str_starts_with($permissions, 'd');
            $isSymlink = str_starts_with($permissions, 'l');
            
            $files[] = [
                'name' => $name,
                'path' => rtrim($path, '/') . '/' . $name,
                'is_directory' => $isDirectory,
                'is_symlink' => $isSymlink,
                'permissions' => $permissions,
                'size' => $isDirectory ? '-' : $this->formatFileSize((int)$size),
                'size_bytes' => (int)$size,
            ];
        }
        
        // Sort: directories first, then files, alphabetically
        usort($files, function($a, $b) {
            if ($a['is_directory'] && !$b['is_directory']) return -1;
            if (!$a['is_directory'] && $b['is_directory']) return 1;
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $files;
    }

    public function navigateTo(string $path)
    {
        $this->browsePath($path);
    }

    public function navigateUp()
    {
        if ($this->currentPath === '/') return;
        
        $parentPath = dirname($this->currentPath);
        if (empty($parentPath)) $parentPath = '/';
        $this->browsePath($parentPath);
    }

    public function uploadFile()
    {
        try {
            $this->authorize('update', $this->resource);
            
            $this->validate([
                'uploadFile' => 'required|file|max:10240', // 10MB max
            ]);
            
            $targetPath = rtrim($this->currentPath, '/') . '/' . $this->uploadFile->getClientOriginalName();
            $escapedPath = escapeshellarg($targetPath);
            
            // Get file content
            $content = file_get_contents($this->uploadFile->getRealPath());
            
            // Create directory if needed
            $dirPath = dirname($targetPath);
            if ($dirPath !== '/') {
                $escapedDir = escapeshellarg($dirPath);
                $mkdirCmd = collect([
                    "docker exec {$this->containerName} mkdir -p {$escapedDir} 2>&1"
                ]);
                instant_remote_process($mkdirCmd, $this->server);
            }
            
            // Upload file using docker cp alternative (write via cat)
            // We need to write the file content to the container
            $tempFile = '/tmp/' . uniqid() . '_' . $this->uploadFile->getClientOriginalName();
            
            // Write to temp file on server
            $escapedTemp = escapeshellarg($tempFile);
            $writeCmd = collect([
                "echo " . escapeshellarg(base64_encode($content)) . " | base64 -d > {$escapedTemp} 2>&1"
            ]);
            instant_remote_process($writeCmd, $this->server);
            
            // Copy to container
            $cpCmd = collect([
                "docker cp {$escapedTemp} {$this->containerName}:{$escapedPath} 2>&1"
            ]);
            $result = instant_remote_process($cpCmd, $this->server, false);
            
            // Clean up temp file
            $rmCmd = collect([
                "rm -f {$escapedTemp} 2>&1"
            ]);
            instant_remote_process($rmCmd, $this->server, false);
            
            $this->successMessage = "File uploaded successfully to {$targetPath}";
            $this->uploadFile = null;
            $this->browsePath($this->currentPath);
            
        } catch (\Throwable $e) {
            $this->errorMessage = 'Upload failed: ' . $e->getMessage();
        }
    }

    public function deleteFile(string $filePath, bool $isDirectory = false)
    {
        try {
            $this->authorize('update', $this->resource);
            
            $escapedPath = escapeshellarg($filePath);
            
            if ($isDirectory) {
                $command = collect([
                    "docker exec {$this->containerName} rm -rf {$escapedPath} 2>&1"
                ]);
            } else {
                $command = collect([
                    "docker exec {$this->containerName} rm -f {$escapedPath} 2>&1"
                ]);
            }
            
            instant_remote_process($command, $this->server);
            
            $this->successMessage = "Deleted: {$filePath}";
            $this->browsePath($this->currentPath);
            
        } catch (\Throwable $e) {
            $this->errorMessage = 'Delete failed: ' . $e->getMessage();
        }
    }

    public function refresh()
    {
        $this->browsePath($this->currentPath);
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function render()
    {
        return view('livewire.project.service.file-browser');
    }
}
