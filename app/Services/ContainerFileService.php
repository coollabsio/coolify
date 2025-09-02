<?php

namespace App\Services;

use App\Models\Application;
use App\Models\FileBrowserLog;
use App\Models\Server;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContainerFileService
{
    protected Server $server;

    public function __construct()
    {
        // This will be injected based on container context
    }

    /**
     * Set the server context for Docker operations
     */
    public function setServer(Server $server): self
    {
        $this->server = $server;

        return $this;
    }

    /**
     * List files and directories in container path
     */
    public function listFiles(string $containerId, string $path = '/', bool $showHidden = false): array
    {
        $this->validateContainerAccess($containerId);
        $path = $this->sanitizePath($path);

        // Get container name from ID
        $containerName = $this->getContainerName($containerId);

        // Build Docker command to list files
        $command = $this->buildListCommand($containerName, $path, $showHidden);

        // Execute command on server
        $output = instant_remote_process([$command], $this->server);

        // Parse output into file structure
        return $this->parseFileList($output, $path);
    }

    /**
     * Upload file to container
     */
    public function uploadFile(string $containerId, UploadedFile $file, string $targetPath, string $permissions = '644'): array
    {
        $this->validateContainerAccess($containerId);
        $this->validateFileUpload($file);

        $targetPath = $this->sanitizePath($targetPath);
        $containerName = $this->getContainerName($containerId);

        // Store file temporarily
        $tempPath = $file->store('temp');
        $realTempPath = Storage::path($tempPath);

        try {
            // Copy file to container
            $containerTargetPath = $targetPath.'/'.$file->getClientOriginalName();
            $copyCommand = "docker cp \"{$realTempPath}\" {$containerName}:\"{$containerTargetPath}\"";

            $output = instant_remote_process([$copyCommand], $this->server);

            // Set permissions
            if ($permissions !== '644') {
                $chmodCommand = "docker exec {$containerName} chmod {$permissions} \"{$containerTargetPath}\"";
                instant_remote_process([$chmodCommand], $this->server);
            }

            // Log the operation
            $this->logOperation('upload', $containerId, $containerTargetPath, [
                'file_size' => $file->getSize(),
                'original_name' => $file->getClientOriginalName(),
            ]);

            return [
                'name' => $file->getClientOriginalName(),
                'path' => $containerTargetPath,
                'size' => $file->getSize(),
                'permissions' => $permissions,
            ];
        } finally {
            // Clean up temporary file
            Storage::delete($tempPath);
        }
    }

    /**
     * Download file from container
     */
    public function downloadFile(string $containerId, string $filePath): string
    {
        $this->validateContainerAccess($containerId);
        $filePath = $this->sanitizePath($filePath);

        $containerName = $this->getContainerName($containerId);

        // Create temporary file path
        $tempPath = storage_path('app/temp/'.Str::random(32));

        try {
            // Copy file from container
            $copyCommand = "docker cp {$containerName}:\"{$filePath}\" \"{$tempPath}\"";
            instant_remote_process([$copyCommand], $this->server);

            if (! file_exists($tempPath)) {
                throw new \Exception('Failed to copy file from container');
            }

            $content = file_get_contents($tempPath);

            // Log the operation
            $this->logOperation('download', $containerId, $filePath);

            return $content;
        } finally {
            // Clean up temporary file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Create directory in container
     */
    public function createDirectory(string $containerId, string $path, string $permissions = '755'): void
    {
        $this->validateContainerAccess($containerId);
        $path = $this->sanitizePath($path);

        $containerName = $this->getContainerName($containerId);

        // Create directory
        $mkdirCommand = "docker exec {$containerName} mkdir -p \"{$path}\"";
        instant_remote_process([$mkdirCommand], $this->server);

        // Set permissions
        if ($permissions !== '755') {
            $chmodCommand = "docker exec {$containerName} chmod {$permissions} \"{$path}\"";
            instant_remote_process([$chmodCommand], $this->server);
        }

        // Log the operation
        $this->logOperation('create_directory', $containerId, $path, ['permissions' => $permissions]);
    }

    /**
     * Delete file or directory from container
     */
    public function deleteItem(string $containerId, string $path, bool $isDirectory = false): void
    {
        $this->validateContainerAccess($containerId);
        $path = $this->sanitizePath($path);

        $containerName = $this->getContainerName($containerId);

        // Delete item
        $deleteCommand = $isDirectory
            ? "docker exec {$containerName} rm -rf \"{$path}\""
            : "docker exec {$containerName} rm -f \"{$path}\"";

        instant_remote_process([$deleteCommand], $this->server);

        // Log the operation
        $this->logOperation('delete', $containerId, $path, ['is_directory' => $isDirectory]);
    }

    /**
     * Update file permissions
     */
    public function updatePermissions(string $containerId, string $path, string $permissions, bool $recursive = false): void
    {
        $this->validateContainerAccess($containerId);
        $path = $this->sanitizePath($path);
        $this->validatePermissions($permissions);

        $containerName = $this->getContainerName($containerId);

        // Update permissions
        $chmodFlags = $recursive ? '-R' : '';
        $chmodCommand = "docker exec {$containerName} chmod {$chmodFlags} {$permissions} \"{$path}\"";

        instant_remote_process([$chmodCommand], $this->server);

        // Log the operation
        $this->logOperation('update_permissions', $containerId, $path, [
            'permissions' => $permissions,
            'recursive' => $recursive,
        ]);
    }

    /**
     * Get container volumes and mounts
     */
    public function getContainerMounts(string $containerId): array
    {
        $this->validateContainerAccess($containerId);
        $containerName = $this->getContainerName($containerId);

        // Get container inspect data
        $inspectCommand = "docker inspect {$containerName}";
        $output = instant_remote_process([$inspectCommand], $this->server);

        $containerData = json_decode($output, true);

        if (! $containerData || ! isset($containerData[0])) {
            throw new \Exception('Failed to inspect container');
        }

        $mounts = $containerData[0]['Mounts'] ?? [];
        $volumes = [];

        foreach ($mounts as $mount) {
            $volumes[] = [
                'type' => $mount['Type'] ?? 'unknown',
                'source' => $mount['Source'] ?? '',
                'destination' => $mount['Destination'] ?? '',
                'mode' => $mount['Mode'] ?? '',
                'rw' => $mount['RW'] ?? false,
                'name' => $mount['Name'] ?? null,
            ];
        }

        return $volumes;
    }

    /**
     * Validate container access permissions
     */
    protected function validateContainerAccess(string $containerId): void
    {
        // Get application by container ID or UUID
        $application = Application::where('uuid', $containerId)
            ->orWhereHas('servers', function ($query) use ($containerId) {
                $query->where('uuid', $containerId);
            })
            ->first();

        if (! $application) {
            throw new \Exception('Container not found or access denied');
        }

        // Check user permissions
        $user = Auth::user();
        if (! $user->can('view', $application)) {
            throw new \Exception('Insufficient permissions to access this container');
        }

        // Set server context
        $this->setServer($application->destination->server);
    }

    /**
     * Sanitize file path to prevent directory traversal
     */
    protected function sanitizePath(string $path): string
    {
        // Remove any attempts at directory traversal
        $path = str_replace(['../', '..\\', '../', '..\\'], '', $path);

        // Ensure path starts with /
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        // Remove duplicate slashes
        $path = preg_replace('#/+#', '/', $path);

        return $path;
    }

    /**
     * Validate file upload
     */
    protected function validateFileUpload(UploadedFile $file): void
    {
        // Check file size (100MB limit)
        $maxSize = 100 * 1024 * 1024; // 100MB
        if ($file->getSize() > $maxSize) {
            throw new \Exception('File size exceeds maximum allowed size of 100MB');
        }

        // Check for dangerous file types
        $dangerousExtensions = ['exe', 'bat', 'cmd', 'scr', 'pif', 'vbs'];
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, $dangerousExtensions)) {
            throw new \Exception('File type not allowed for security reasons');
        }
    }

    /**
     * Validate permissions format
     */
    protected function validatePermissions(string $permissions): void
    {
        if (! preg_match('/^[0-7]{3,4}$/', $permissions)) {
            throw new \Exception('Invalid permissions format. Use octal format (e.g., 755, 644)');
        }
    }

    /**
     * Get container name from ID
     */
    protected function getContainerName(string $containerId): string
    {
        // This could be the container ID or application UUID
        // We need to resolve it to the actual container name
        $application = Application::where('uuid', $containerId)->first();

        if ($application) {
            return generateApplicationContainerName($application);
        }

        // If not found, assume it's already a container name
        return $containerId;
    }

    /**
     * Build command to list files
     */
    protected function buildListCommand(string $containerName, string $path, bool $showHidden): string
    {
        $lsFlags = '-la';
        if (! $showHidden) {
            $lsFlags = '-l';
        }

        return "docker exec {$containerName} ls {$lsFlags} \"{$path}\"";
    }

    /**
     * Parse file list output
     */
    protected function parseFileList(string $output, string $currentPath): array
    {
        $lines = explode("\n", trim($output));
        $files = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, 'total ')) {
                continue;
            }

            // Parse ls -la output
            $parts = preg_split('/\s+/', $line, 9);
            if (count($parts) < 9) {
                continue;
            }

            $permissions = $parts[0];
            $type = substr($permissions, 0, 1);
            $name = $parts[8];

            // Skip . and .. entries
            if ($name === '.' || $name === '..') {
                continue;
            }

            $files[] = [
                'name' => $name,
                'type' => $type === 'd' ? 'directory' : 'file',
                'permissions' => $permissions,
                'size' => $type === 'd' ? 0 : (int) $parts[4],
                'owner' => $parts[2],
                'group' => $parts[3],
                'modified' => $parts[5].' '.$parts[6].' '.$parts[7],
                'path' => rtrim($currentPath, '/').'/'.$name,
            ];
        }

        return $files;
    }

    /**
     * Log file operation
     */
    protected function logOperation(string $operation, string $containerId, string $path, array $metadata = []): void
    {
        FileBrowserLog::create([
            'user_id' => Auth::id(),
            'container_id' => $containerId,
            'operation' => $operation,
            'path' => $path,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
