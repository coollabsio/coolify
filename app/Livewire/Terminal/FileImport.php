<?php

namespace App\Livewire\Terminal;

use App\Helpers\TerminalFileHelper;
use App\Jobs\CleanupExpiredTerminalFilesJob;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class FileImport extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public string $selectedUuid;

    public ?string $selectedServerUuid = null;

    public ?string $targetName = null;

    public $uploadedFile;

    public ?string $filename = null;

    public ?string $filesize = null;

    public ?string $filePath = null;

    public bool $isUploading = false;

    public int $progress = 0;

    public bool $error = false;

    public int $expirationMinutes = 60; // Default: 1 hour

    public function updatedUploadedFile()
    {
        $this->validate([
            'uploadedFile' => 'required|file|max:10485760', // 10GB in KB
        ]);

        $this->filename = $this->uploadedFile->getClientOriginalName();
        $this->filesize = number_format($this->uploadedFile->getSize() / 1024 / 1024, 2) . ' MB';
        $this->isUploading = false;

        $this->dispatch('success', 'File uploaded successfully!');
    }

    #[Computed]
    public function expirationOptions()
    {
        return [
            5 => '5 minutes',
            15 => '15 minutes',
            30 => '30 minutes',
            60 => '1 hour',
            120 => '2 hours',
            240 => '4 hours',
            480 => '8 hours',
            1440 => '24 hours',
        ];
    }

    #[Computed]
    public function uploadedFiles()
    {
        $files = [];
        $baseDir = storage_path('app/terminal-uploads');
        $currentUserId = Auth::id();

        if (! is_dir($baseDir)) {
            return $files;
        }

        // Scan subdirectories for current user's uploaded files
        $directories = glob($baseDir . '/user_' . $currentUserId . '_*', GLOB_ONLYDIR);

        // Collect all server IDs to load them in bulk
        $serverIds = [];
        $filesData = [];

        foreach ($directories as $dir) {
            $dirFiles = glob($dir . '/*');
            foreach ($dirFiles as $filePath) {
                if (is_file($filePath)) {
                    $filename = basename($filePath);

                    // Parse metadata from filename
                    $metadata = TerminalFileHelper::parseFilename($filename);

                    if (!$metadata) {
                        // Skip files that don't match our format
                        continue;
                    }

                    $serverIds[] = $metadata['server_id'];
                    $filesData[] = [
                        'filename' => $filename,
                        'display_name' => TerminalFileHelper::getDisplayName($filename),
                        'directory' => basename($dir),
                        'size' => filesize($filePath),
                        'uploaded_at' => $metadata['uploaded_at'],
                        'expires_at' => $metadata['expires_at'],
                        'server_id' => $metadata['server_id'],
                        'container_uuid' => $metadata['container_uuid'],
                    ];
                }
            }
        }

        // Load all servers in one query
        $servers = Server::whereIn('id', array_unique($serverIds))->get()->keyBy('id');

        // Add server name to each file
        foreach ($filesData as $fileData) {
            $server = $servers->get($fileData['server_id']);
            $fileData['server_name'] = $server ? $server->name : 'Unknown';
            $files[] = $fileData;
        }

        // Sort by upload date (newest first)
        usort($files, function ($a, $b) {
            return $b['uploaded_at'] <=> $a['uploaded_at'];
        });

        return $files;
    }

    public function copyPath(string $directory, string $filename)
    {
        // Security: validate inputs to prevent path traversal
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false ||
            strpos($directory, '..') !== false || strpos($directory, '/') !== false) {
            $this->dispatch('error', 'Invalid filename or directory');
            return;
        }

        // Security: verify directory belongs to current user
        $currentUserId = Auth::id();
        if (! str_starts_with($directory, 'user_' . $currentUserId . '_')) {
            $this->dispatch('error', 'Unauthorized access');
            return;
        }

        // Parse metadata from filename
        $metadata = TerminalFileHelper::parseFilename($filename);

        if (!$metadata) {
            $this->dispatch('error', 'Invalid file format');
            return;
        }

        // Generate server path
        $serverPath = TerminalFileHelper::generateServerPath($filename);

        // Return the actual server path
        $this->dispatch('path-copied', path: $serverPath);
        $this->dispatch('success', 'Path copied to clipboard!');
    }

    public function deleteFile(string $directory, string $filename)
    {
        // Security: validate inputs to prevent path traversal
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false ||
            strpos($directory, '..') !== false || strpos($directory, '/') !== false) {
            $this->dispatch('error', 'Invalid filename or directory');
            return;
        }

        // Security: verify directory belongs to current user
        $currentUserId = Auth::id();
        if (! str_starts_with($directory, 'user_' . $currentUserId . '_')) {
            $this->dispatch('error', 'Unauthorized access');
            return;
        }

        try {
            // Parse metadata from filename
            $metadata = TerminalFileHelper::parseFilename($filename);

            if (!$metadata) {
                $this->dispatch('error', 'Invalid file format');
                return;
            }

            // Find and delete the file
            $baseDir = storage_path('app/terminal-uploads');
            $dir = $baseDir . '/' . $directory;
            $filePath = $dir . '/' . $filename;

            if (! file_exists($filePath)) {
                $this->dispatch('error', 'File not found');
                return;
            }

            // Security: double-check the full path contains user ID
            $realPath = realpath($filePath);
            $realBaseDir = realpath($baseDir);

            if ($realPath === false || $realBaseDir === false ||
                ! str_starts_with($realPath, $realBaseDir . '/user_' . $currentUserId . '_')) {
                $this->dispatch('error', 'Unauthorized access');
                return;
            }

            // Delete local file
            unlink($filePath);

            // Delete remote files if server exists
            $server = Server::find($metadata['server_id']);

            if ($server) {
                // Generate server path
                $serverPath = TerminalFileHelper::generateServerPath($filename);
                $escapedServerPath = escapeshellarg($serverPath);

                instant_remote_process([
                    "rm -f {$escapedServerPath}"
                ], $server, throwError: false);

                // Delete from container if applicable
                if ($metadata['container_uuid']) {
                    $escapedContainerUuid = escapeshellarg($metadata['container_uuid']);
                    $containerPath = TerminalFileHelper::generateContainerPath($filename);
                    $escapedContainerPath = escapeshellarg($containerPath);

                    instant_remote_process([
                        "docker exec {$escapedContainerUuid} rm -f {$escapedContainerPath} 2>/dev/null || true"
                    ], $server, throwError: false);
                }
            }

            // Try to delete the empty directory
            $files = scandir($dir);
            if (count($files) === 2) { // Only . and ..
                rmdir($dir);
            }

            $this->dispatch('success', 'File deleted successfully!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},FileUploadCompleted" => 'handleUploadCompleted',
        ];
    }

    public function mount(string $selectedUuid, string $targetName, ?string $selectedServerUuid = null)
    {
        $this->selectedUuid = $selectedUuid;
        $this->targetName = $targetName;
        $this->selectedServerUuid = $selectedServerUuid;
    }

    public function handleUploadCompleted()
    {
        // Refresh the component after upload
        $this->dispatch('success', 'File uploaded successfully!');
    }

    public function generateFilePath()
    {
        if (empty($this->filename)) {
            $this->dispatch('error', 'No file uploaded yet.');

            return;
        }

        try {
            // Determine server UUID
            $serverUuid = $this->selectedServerUuid ?? $this->selectedUuid;
            $server = Server::ownedByCurrentTeam()->whereUuid($serverUuid)->first();

            if (! $server) {
                $this->dispatch('error', 'Server not found.');

                return;
            }

            $isContainer = ($this->selectedServerUuid && $this->selectedUuid !== $this->selectedServerUuid);

            // Generate unique file identifier
            $uploadId = uniqid('terminal_', true);
            $originalFilename = basename($this->filename);
            $userId = Auth::id();

            // Calculate expiration timestamp
            $expiresAt = now()->addMinutes($this->expirationMinutes)->timestamp;

            // Generate filename with embedded metadata
            $sanitizedFilename = TerminalFileHelper::generateFilename(
                $originalFilename,
                $expiresAt,
                $server->id,
                $isContainer ? $this->selectedUuid : null
            );

            $storageDir = "terminal-uploads/user_{$userId}_{$uploadId}";
            $storagePath = storage_path("app/{$storageDir}");

            // Get the uploaded file from Livewire
            if (! $this->uploadedFile) {
                $this->dispatch('error', 'Uploaded file not found. Please try uploading again.');

                return;
            }

            // Create directory and store file
            if (! file_exists($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $finalPath = "{$storagePath}/{$sanitizedFilename}";
            $this->uploadedFile->storeAs($storageDir, $sanitizedFilename);

            // Generate server path
            $serverTmpPath = TerminalFileHelper::generateServerPath($sanitizedFilename);
            $safeServerTmpPath = escapeshellarg($serverTmpPath);
            instant_scp($finalPath, $safeServerTmpPath, $server);

            // If it's a container, copy to container
            if ($isContainer) {
                $containerPath = TerminalFileHelper::generateContainerPath($sanitizedFilename);
                $safeContainer = escapeshellarg($this->selectedUuid);
                $safeContainerPath = escapeshellarg($containerPath);

                instant_remote_process([
                    "docker cp {$safeServerTmpPath} {$safeContainer}:{$safeContainerPath}",
                ], $server);

                $this->filePath = $containerPath;
            } else {
                $this->filePath = $serverTmpPath;
            }

            // Schedule cleanup job
            CleanupExpiredTerminalFilesJob::dispatch(
                $finalPath,
                $serverTmpPath,
                $server->id,
                $isContainer ? $this->selectedUuid : null,
                $sanitizedFilename
            )->delay(now()->addMinutes($this->expirationMinutes));

            $this->dispatch('success', "File ready! Path: {$this->filePath}");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.terminal.file-import');
    }

    /**
     * Workaround for Livewire toJSON serialization issue
     * This prevents "Public method [toJSON] not found" errors
     */
    public function toJSON()
    {
        return json_encode([
            'selectedUuid' => $this->selectedUuid,
            'targetName' => $this->targetName,
            'selectedServerUuid' => $this->selectedServerUuid,
        ]);
    }
}
