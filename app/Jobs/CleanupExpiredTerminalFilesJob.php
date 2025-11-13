<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupExpiredTerminalFilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 300; // 5 minutes

    public function __construct(
        public string $localPath,
        public string $serverPath,
        public int $serverId,
        public ?string $containerUuid,
        public string $filename
    ) {
    }

    public function handle(): void
    {
        try {
            // Delete local file
            if (file_exists($this->localPath)) {
                unlink($this->localPath);
                Log::info("Cleaned up local terminal file: {$this->localPath}");
            }

            // Delete file from server
            $server = Server::find($this->serverId);
            if ($server) {
                // Remove from server - escape shell arguments to prevent injection
                $escapedServerPath = escapeshellarg($this->serverPath);
                $result = instant_remote_process([
                    "rm -f {$escapedServerPath}"
                ], $server, throwError: false);

                if ($result) {
                    Log::info("Cleaned up server terminal file: {$this->serverPath}");
                }

                // If container was specified, remove from container as well
                if ($this->containerUuid) {
                    $escapedContainerUuid = escapeshellarg($this->containerUuid);
                    $escapedFilename = escapeshellarg($this->filename);
                    $containerPath = "/tmp/{$this->filename}"; // For logging only

                    instant_remote_process([
                        "docker exec {$escapedContainerUuid} rm -f /tmp/{$escapedFilename} 2>/dev/null || true"
                    ], $server, throwError: false);

                    Log::info("Cleaned up container terminal file: {$containerPath}");
                }
            }

            // Clean up empty parent directory
            $parentDir = dirname($this->localPath);
            if (is_dir($parentDir) && count(scandir($parentDir)) === 2) { // Only . and ..
                rmdir($parentDir);
            }
        } catch (\Throwable $e) {
            Log::error("Failed to cleanup terminal file: {$e->getMessage()}", [
                'localPath' => $this->localPath,
                'serverPath' => $this->serverPath,
                'serverId' => $this->serverId,
            ]);

            // Don't fail the job, just log the error
            // Files will eventually be cleaned up by manual cleanup or system maintenance
        }
    }
}
