<?php

namespace App\Actions\Terminal;

use App\Enums\TerminalUploadedFileStatus;
use App\Models\Server;
use App\Models\TerminalUploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteTerminalUploadedFile
{
    public function __invoke(TerminalUploadedFile $terminalUploadedFile): void
    {
        DB::transaction(function () use ($terminalUploadedFile): void {
            $terminalUploadedFile->forceFill([
                'status' => TerminalUploadedFileStatus::Deleting,
                'cleanup_attempts' => $terminalUploadedFile->cleanup_attempts + 1,
                'last_cleanup_error' => null,
            ])->save();
        });

        try {
            $server = ! is_null($terminalUploadedFile->server_id) ? Server::find($terminalUploadedFile->server_id) : null;

            if ($server && filled($terminalUploadedFile->server_path)) {
                $this->deleteServerFile($server, $terminalUploadedFile->server_path);
            } elseif (! is_null($terminalUploadedFile->server_id)) {
                Log::warning('Skipping remote terminal file cleanup because server is missing.', [
                    'terminal_uploaded_file_id' => $terminalUploadedFile->id,
                    'server_id' => $terminalUploadedFile->server_id,
                ]);
            }

            if ($server && filled($terminalUploadedFile->container_uuid) && filled($terminalUploadedFile->container_path)) {
                $this->deleteContainerFile($server, $terminalUploadedFile->container_uuid, $terminalUploadedFile->container_path);
            }

            $this->deleteLocalFile($terminalUploadedFile->local_path);
            $this->cleanupParentDirectories($terminalUploadedFile->local_path);

            DB::transaction(function () use ($terminalUploadedFile): void {
                $terminalUploadedFile->forceFill([
                    'status' => TerminalUploadedFileStatus::Deleted,
                    'deleted_at' => now(),
                    'last_cleanup_error' => null,
                ])->save();
            });
        } catch (\Throwable $e) {
            DB::transaction(function () use ($terminalUploadedFile, $e): void {
                $terminalUploadedFile->forceFill([
                    'status' => TerminalUploadedFileStatus::DeleteFailed,
                    'last_cleanup_error' => $e->getMessage(),
                ])->save();
            });

            throw $e;
        }
    }

    private function deleteServerFile(Server $server, string $serverPath): void
    {
        $escapedServerPath = escapeshellarg($serverPath);

        instant_remote_process([
            "rm -f -- {$escapedServerPath}",
        ], $server, throwError: false);

        $remoteStillExists = instant_remote_process([
            "test ! -e {$escapedServerPath} && echo deleted || echo exists",
        ], $server, throwError: false);

        if ($remoteStillExists !== 'deleted') {
            throw new \RuntimeException("Failed to delete remote terminal file: {$serverPath}");
        }
    }

    private function deleteContainerFile(Server $server, string $containerUuid, string $containerPath): void
    {
        $escapedContainerUuid = escapeshellarg($containerUuid);
        $escapedContainerPath = escapeshellarg($containerPath);

        instant_remote_process([
            "docker exec -u 0 {$escapedContainerUuid} rm -f -- {$escapedContainerPath} 2>/dev/null || true",
        ], $server, throwError: false);

        $containerDeletionState = instant_remote_process([
            "if docker container inspect {$escapedContainerUuid} >/dev/null 2>&1; then docker exec -u 0 {$escapedContainerUuid} test ! -e {$escapedContainerPath} 2>/dev/null && echo deleted || echo exists; else echo deleted; fi",
        ], $server, throwError: false);

        if ($containerDeletionState !== 'deleted') {
            throw new \RuntimeException("Failed to delete container terminal file: {$containerPath}");
        }
    }

    private function deleteLocalFile(?string $localPath): void
    {
        if (blank($localPath)) {
            return;
        }

        if (file_exists($localPath)) {
            unlink($localPath);
        }
    }

    private function cleanupParentDirectories(?string $localPath): void
    {
        if (blank($localPath)) {
            return;
        }

        $allowedRoots = array_filter([
            realpath(storage_path('app/terminal-uploads')),
            realpath(storage_path('app/terminal-uploads-pending')),
        ]);

        $currentDirectory = dirname($localPath);

        while ($currentDirectory && $currentDirectory !== '.' && $currentDirectory !== DIRECTORY_SEPARATOR) {
            $realCurrentDirectory = realpath($currentDirectory);

            if ($realCurrentDirectory === false) {
                break;
            }

            $isWithinAllowedRoot = collect($allowedRoots)->contains(
                fn (string $root): bool => str_starts_with($realCurrentDirectory, $root)
            );

            if (! $isWithinAllowedRoot) {
                break;
            }

            $contents = scandir($realCurrentDirectory);
            if ($contents === false || count($contents) > 2) {
                break;
            }

            rmdir($realCurrentDirectory);
            $currentDirectory = dirname($realCurrentDirectory);
        }
    }
}
