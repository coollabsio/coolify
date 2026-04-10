<?php

namespace App\Livewire\Terminal;

use App\Actions\Terminal\DeleteTerminalUploadedFile;
use App\Enums\TerminalUploadedFileStatus;
use App\Helpers\TerminalFileHelper;
use App\Models\Server;
use App\Models\TerminalUploadedFile;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FileImport extends Component
{
    use AuthorizesRequests;

    public string $selectedUuid = 'default';

    #[Locked]
    public array $servers = [];

    #[Locked]
    public array $containers = [];

    public ?string $filename = null;

    public ?string $filesize = null;

    public ?string $filePath = null;

    public bool $isUploading = false;

    public int $progress = 0;

    public bool $error = false;

    public int $expirationMinutes = 60;

    public bool $hasPendingUpload = false;

    public ?string $pendingUploadUuid = null;

    public ?string $pendingDeleteFileUuid = null;

    public function mount(array $servers, array $containers, string $selectedUuid = 'default'): void
    {
        $this->authorizeTerminalAccess();

        $this->servers = $servers;
        $this->containers = $containers;
        $this->selectedUuid = $this->isSelectableTarget($selectedUuid) ? $selectedUuid : 'default';
    }

    #[Computed]
    public function expirationOptions(): array
    {
        return [
            1 => '1 minute',
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
    public function uploadedFiles(): array
    {
        return TerminalUploadedFile::query()
            ->ownedByCurrentUserAndTeam()
            ->visible()
            ->with('server:id,name')
            ->orderByDesc('uploaded_at')
            ->get()
            ->map(function (TerminalUploadedFile $terminalUploadedFile): array {
                return [
                    'uuid' => $terminalUploadedFile->uuid,
                    'filename' => $terminalUploadedFile->stored_filename,
                    'display_name' => $terminalUploadedFile->original_name ?: TerminalFileHelper::getDisplayName($terminalUploadedFile->stored_filename),
                    'size' => $terminalUploadedFile->size_bytes,
                    'uploaded_at' => $terminalUploadedFile->uploaded_at?->timestamp,
                    'expires_at' => $terminalUploadedFile->expires_at?->timestamp,
                    'server_name' => $terminalUploadedFile->server?->name ?? 'Unknown',
                    'container_uuid' => $terminalUploadedFile->container_uuid,
                    'path' => $terminalUploadedFile->container_path ?: $terminalUploadedFile->server_path,
                ];
            })
            ->all();
    }

    #[Computed]
    public function targetName(): ?string
    {
        return data_get($this->resolveSelectedTarget(), 'label');
    }

    public function updatedSelectedUuid(string $value): void
    {
        $this->authorizeTerminalAccess();

        if ($value === 'default') {
            $this->filePath = null;
            $this->error = false;

            return;
        }

        if (! $this->isSelectableTarget($value)) {
            $this->selectedUuid = 'default';
            $this->dispatchError('Invalid target selected.');

            return;
        }

        $this->filePath = null;
        $this->error = false;
    }

    public function registerUploadedFile(string $fileUuid, string $originalName, int $size): void
    {
        $this->authorizeTerminalAccess();

        $terminalUploadedFile = $this->findPendingUploadForCurrentUser($fileUuid);

        if (! $terminalUploadedFile) {
            $this->dispatchError('Uploaded file not found. Please try again.');

            return;
        }

        $this->error = false;
        $this->pendingUploadUuid = $terminalUploadedFile->uuid;
        $this->filename = basename($originalName);
        $this->filesize = formatBytes($size);
        $this->filePath = null;
        $this->hasPendingUpload = true;
        $this->isUploading = false;
        $this->progress = 100;

        $this->dispatch('success', 'File uploaded successfully!');
    }

    public function resetPendingUpload(): void
    {
        $this->authorizeTerminalAccess();

        $terminalUploadedFile = $this->findPendingUploadForCurrentUser($this->pendingUploadUuid);
        if ($terminalUploadedFile) {
            app(DeleteTerminalUploadedFile::class)($terminalUploadedFile);
        }

        $this->clearUploadState();
    }

    public function handleUploadError(string $message): void
    {
        $this->authorizeTerminalAccess();
        $this->dispatchError($message);
    }

    public function copyPath(string $fileUuid): void
    {
        $this->authorizeTerminalAccess();

        $terminalUploadedFile = $this->findVisibleFileForCurrentUser($fileUuid);

        if (! $terminalUploadedFile) {
            $this->dispatchError('File not found.');

            return;
        }

        $path = $terminalUploadedFile->container_path ?: $terminalUploadedFile->server_path;

        if (blank($path)) {
            $this->dispatchError('File path is unavailable.');

            return;
        }

        $this->dispatch('path-copied', path: $path);
        $this->dispatch('success', 'Path copied to clipboard!');
    }

    public function prepareFileDeletion(string $fileUuid): void
    {
        $this->authorizeTerminalAccess();

        $terminalUploadedFile = $this->findVisibleFileForCurrentUser($fileUuid);

        if (! $terminalUploadedFile) {
            $this->pendingDeleteFileUuid = null;
            $this->dispatchError('File not found.');

            return;
        }

        $this->pendingDeleteFileUuid = $terminalUploadedFile->uuid;
    }

    public function confirmDeleteFile(): void
    {
        $this->authorizeTerminalAccess();

        $terminalUploadedFile = $this->findVisibleFileForCurrentUser((string) $this->pendingDeleteFileUuid);

        if (! $terminalUploadedFile) {
            $this->pendingDeleteFileUuid = null;
            $this->dispatchError('File not found.');

            return;
        }

        $this->deleteTerminalUploadedFile($terminalUploadedFile);
        $this->pendingDeleteFileUuid = null;
    }

    public function deleteFile(string $fileUuid): void
    {
        $this->authorizeTerminalAccess();

        $terminalUploadedFile = $this->findVisibleFileForCurrentUser($fileUuid);

        if (! $terminalUploadedFile) {
            $this->dispatchError('File not found.');

            return;
        }

        $this->deleteTerminalUploadedFile($terminalUploadedFile);
    }

    private function deleteTerminalUploadedFile(TerminalUploadedFile $terminalUploadedFile): void
    {
        try {
            app(DeleteTerminalUploadedFile::class)($terminalUploadedFile);
            $this->error = false;
            $this->dispatch('success', 'File deleted successfully!');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function generateFilePath(): void
    {
        $this->authorizeTerminalAccess();

        $target = $this->resolveSelectedTarget();
        if (! $target) {
            $this->dispatchError('Please select a server or container.');

            return;
        }

        $pendingUpload = $this->findPendingUploadForCurrentUser($this->pendingUploadUuid);

        if (! $pendingUpload || blank($this->filename)) {
            $this->dispatchError('No file uploaded yet.');

            return;
        }

        try {
            $server = Server::ownedByCurrentTeam()->whereUuid($target['server_uuid'])->first();

            if (! $server) {
                $this->dispatchError('Server not found.');

                return;
            }

            if (! is_file($pendingUpload->local_path)) {
                $this->dispatchError('Uploaded file not found. Please upload it again.');

                return;
            }

            $realPendingPath = realpath($pendingUpload->local_path);
            $realPendingBaseDir = realpath($this->pendingUploadsBaseDirectory());

            if ($realPendingPath === false || $realPendingBaseDir === false || ! str_starts_with($realPendingPath, $realPendingBaseDir)) {
                $this->dispatchError('Unauthorized upload path.');

                return;
            }

            $isContainer = $target['is_container'];
            $expiresAt = now()->addMinutes($this->expirationMinutes);
            $sanitizedFilename = TerminalFileHelper::generateFilename(
                $pendingUpload->original_name,
                $expiresAt->timestamp,
                $server->id,
                $isContainer ? $target['uuid'] : null,
            );

            $storageDir = sprintf('terminal-uploads/user_%d_%s', Auth::id(), $pendingUpload->upload_token);
            $storagePath = storage_path("app/{$storageDir}");

            if (! is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $finalPath = "{$storagePath}/{$sanitizedFilename}";
            $serverTmpPath = TerminalFileHelper::generateServerPath($sanitizedFilename);
            $safeServerTmpPath = escapeshellarg($serverTmpPath);

            if (! rename($pendingUpload->local_path, $finalPath)) {
                $this->dispatchError('Failed to finalize uploaded file. Please try again.');

                return;
            }

            $this->cleanupDirectoryIfEmpty(dirname($pendingUpload->local_path));
            $this->cleanupDirectoryIfEmpty($this->pendingUploadsBaseDirectory());

            instant_scp($finalPath, $safeServerTmpPath, $server);
            instant_remote_process([
                "chmod 600 {$safeServerTmpPath}",
            ], $server);

            $containerPath = null;
            if ($isContainer) {
                $containerPath = TerminalFileHelper::generateContainerPath($sanitizedFilename);
                $safeContainer = escapeshellarg($target['uuid']);
                $safeContainerPath = escapeshellarg($containerPath);

                instant_remote_process([
                    "docker cp {$safeServerTmpPath} {$safeContainer}:{$safeContainerPath}",
                    "docker exec -u 0 {$safeContainer} chmod 600 {$safeContainerPath}",
                ], $server);

                $this->filePath = $containerPath;
            } else {
                $this->filePath = $serverTmpPath;
            }

            $pendingUpload->forceFill([
                'server_id' => $server->id,
                'container_uuid' => $isContainer ? $target['uuid'] : null,
                'stored_filename' => $sanitizedFilename,
                'local_path' => $finalPath,
                'server_path' => $serverTmpPath,
                'container_path' => $containerPath,
                'status' => TerminalUploadedFileStatus::Active,
                'expires_at' => $expiresAt,
                'finalized_at' => now(),
                'last_cleanup_error' => null,
            ])->save();

            $this->pendingUploadUuid = null;
            $this->hasPendingUpload = false;
            $this->error = false;
            $this->dispatch('success', "File ready! Path: {$this->filePath}");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.terminal.file-import');
    }

    private function authorizeTerminalAccess(): void
    {
        Gate::authorize('canAccessTerminal');
    }

    private function pendingUploadsBaseDirectory(): string
    {
        return storage_path('app/terminal-uploads-pending/user_'.Auth::id());
    }

    private function cleanupDirectoryIfEmpty(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $contents = scandir($directory);
        if ($contents !== false && count($contents) === 2) {
            rmdir($directory);
        }
    }

    private function clearUploadState(bool $resetProgress = true): void
    {
        $this->hasPendingUpload = false;
        $this->pendingUploadUuid = null;
        $this->filePath = null;
        $this->isUploading = false;
        $this->error = false;

        if ($resetProgress) {
            $this->progress = 0;
        }
    }

    private function dispatchError(string $message): void
    {
        $this->error = true;
        $this->isUploading = false;
        $this->dispatch('error', $message);
    }

    private function isSelectableTarget(string $uuid): bool
    {
        return $this->resolveTargetByUuid($uuid) !== null;
    }

    private function findPendingUploadForCurrentUser(?string $fileUuid): ?TerminalUploadedFile
    {
        if (blank($fileUuid)) {
            return null;
        }

        return TerminalUploadedFile::query()
            ->ownedByCurrentUserAndTeam()
            ->where('uuid', $fileUuid)
            ->whereNull('deleted_at')
            ->whereNull('finalized_at')
            ->where('status', TerminalUploadedFileStatus::Pending)
            ->first();
    }

    private function findVisibleFileForCurrentUser(string $fileUuid): ?TerminalUploadedFile
    {
        return TerminalUploadedFile::query()
            ->ownedByCurrentUserAndTeam()
            ->visible()
            ->where('uuid', $fileUuid)
            ->first();
    }

    /**
     * @return array{uuid:string,server_uuid:string,label:string,is_container:bool}|null
     */
    private function resolveSelectedTarget(): ?array
    {
        return $this->resolveTargetByUuid($this->selectedUuid);
    }

    /**
     * @return array{uuid:string,server_uuid:string,label:string,is_container:bool}|null
     */
    private function resolveTargetByUuid(string $uuid): ?array
    {
        foreach ($this->servers as $server) {
            if (($server['uuid'] ?? null) === $uuid) {
                return [
                    'uuid' => $uuid,
                    'server_uuid' => $uuid,
                    'label' => sprintf('%s (Server)', $server['name']),
                    'is_container' => false,
                ];
            }
        }

        foreach ($this->containers as $container) {
            if (($container['uuid'] ?? null) === $uuid && filled($container['server_uuid'] ?? null)) {
                return [
                    'uuid' => $uuid,
                    'server_uuid' => $container['server_uuid'],
                    'label' => $container['name'] ?? $uuid,
                    'is_container' => true,
                ];
            }
        }

        return null;
    }
}
