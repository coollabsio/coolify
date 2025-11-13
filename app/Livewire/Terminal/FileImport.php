<?php

namespace App\Livewire\Terminal;

use App\Jobs\CleanupExpiredTerminalFilesJob;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
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
            15 => '15 minutes',
            30 => '30 minutes',
            60 => '1 hour',
            120 => '2 hours',
            240 => '4 hours',
            480 => '8 hours',
            1440 => '24 hours',
        ];
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
            $sanitizedFilename = basename($this->filename);
            $storageDir = "terminal-uploads/{$uploadId}";
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

            // Copy file to server's temporary directory
            $serverTmpPath = "/tmp/coolify_import_{$uploadId}_{$sanitizedFilename}";
            $safeServerTmpPath = escapeshellarg($serverTmpPath);
            instant_scp($finalPath, $safeServerTmpPath, $server);

            // If it's a container, copy to container
            if ($isContainer) {
                $containerPath = "/tmp/{$sanitizedFilename}";
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
