<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Copies the data of a bind mount (a directory or a file) of a resource to the same mount of its
 * clone, on one server. The caller makes sure that both paths are inside the resource directories.
 */
class BindMountCloneJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        protected string $sourcePath,
        protected string $targetPath,
        protected Server $server,
    ) {
        $this->onQueue('high');
    }

    public function tags(): array
    {
        return [Server::class.':'.$this->server->id];
    }

    public function handle(): void
    {
        validateShellSafePath($this->sourcePath, 'storage path');
        validateShellSafePath($this->targetPath, 'storage path');
        $source = escapeshellarg($this->sourcePath);
        $target = escapeshellarg($this->targetPath);
        $targetParent = escapeshellarg(dirname($this->targetPath));

        $sourceType = $this->pathType($source);
        if ($sourceType === 'directory') {
            // A file at the target is a `content:` file of the clone. The legacy parser made an empty directory there.
            if (in_array($this->pathType($target), ['missing', 'directory'], true)) {
                instant_remote_process(["mkdir -p {$target}", "cp -a {$source}/. {$target}/"], $this->server);
            }
        } elseif (in_array($sourceType, ['file', 'empty-file'], true)) {
            // Docker creates a missing bind source as an empty directory, and Coolify an empty file: both are replaced.
            // rmdir removes only an empty directory.
            instant_remote_process(["mkdir -p {$targetParent}", "rmdir {$target} 2>/dev/null || true"], $this->server);
            if (in_array($this->pathType($target), ['missing', 'empty-file'], true)) {
                instant_remote_process(["cp -aT {$source} {$target}"], $this->server);
            }
        }
    }

    /**
     * stat does not follow a symbolic link, so a link is never copied or written through.
     *
     * @return 'directory'|'file'|'empty-file'|'missing'|'other'
     */
    private function pathType(string $escapedPath): string
    {
        $type = trim((string) instant_remote_process(["stat -c %F {$escapedPath} 2>/dev/null || echo missing"], $this->server));

        return match ($type) {
            'directory' => 'directory',
            'regular file' => 'file',
            'regular empty file' => 'empty-file',
            'missing' => 'missing',
            default => 'other',
        };
    }
}
