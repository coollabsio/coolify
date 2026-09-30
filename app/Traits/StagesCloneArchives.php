<?php

namespace App\Traits;

use App\Models\Server;

/**
 * Clone jobs move an archive between servers with scp, which reads and writes as the SSH user.
 * On the Coolify host, /data/coolify is closed to a non-root SSH user, so the archive goes through
 * a directory in /var/tmp (on disk, not tmpfs) that only that user and root can open.
 */
trait StagesCloneArchives
{
    protected string $cloneDir = '/data/coolify/clone';

    protected function cloneArchiveDirectory(Server $server, string $name): string
    {
        return ($server->isNonRoot() ? '/var/tmp/coolify-clone' : $this->cloneDir)."/{$name}";
    }

    /**
     * @return list<string>
     */
    protected function prepareCloneArchiveDirectory(Server $server, string $directory): array
    {
        $escapedDirectory = escapeshellarg($directory);

        if ($server->isNonRoot()) {
            return [
                "mkdir -p {$escapedDirectory}",
                'chown '.escapeshellarg($server->user)." {$escapedDirectory}",
                "chmod 700 {$escapedDirectory}",
            ];
        }

        return [
            "mkdir -p {$escapedDirectory}",
            "chmod 777 {$escapedDirectory}",
        ];
    }
}
