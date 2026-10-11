<?php

namespace App\Traits;

use App\Models\Server;
use RuntimeException;
use Throwable;

/**
 * Clone jobs move an archive between servers with scp, which reads and writes as the SSH user.
 * On the Coolify host, /data/coolify is closed to a non-root SSH user, so the archive goes through
 * a directory in /var/tmp (on disk, not tmpfs) that only that user and root can open. /var/tmp is
 * writable by every local user, so the directory gets a random name from mktemp: a fixed name could
 * be created beforehand, or be a symlink that the sudo chown and chmod would follow.
 */
trait StagesCloneArchives
{
    protected string $cloneDir = '/data/coolify/clone';

    /**
     * Creates the directory for the clone archive on the server and returns its path.
     */
    protected function createCloneArchiveDirectory(Server $server, string $name): string
    {
        if (! $server->isNonRoot()) {
            $directory = "{$this->cloneDir}/{$name}";
            $escapedDirectory = escapeshellarg($directory);
            instant_remote_process([
                "mkdir -p {$escapedDirectory}",
                "chmod 777 {$escapedDirectory}",
            ], $server);

            return $directory;
        }

        // The sudo parser runs mktemp as root, so the new directory belongs to root with mode 700.
        // mktemp creates a new directory and never reuses an existing path or symlink. BusyBox
        // (Alpine) randomizes only the last 6 X, which still matches the pattern below.
        $directory = trim((string) instant_remote_process(['mktemp -d /var/tmp/coolify-clone.XXXXXXXXXX'], $server));
        if (preg_match('#\A/var/tmp/coolify-clone\.[A-Za-z0-9]{10}\z#', $directory) !== 1) {
            throw new RuntimeException('Could not create a temporary clone directory on server '.$server->name.'.');
        }

        $escapedDirectory = escapeshellarg($directory);
        try {
            // scp reads and writes the archive as the SSH user.
            instant_remote_process([
                'chown '.escapeshellarg($server->user)." {$escapedDirectory}",
                "chmod 700 {$escapedDirectory}",
            ], $server);
        } catch (Throwable $e) {
            $this->removeCloneArchiveDirectory($server, $directory);

            throw $e;
        }

        return $directory;
    }

    protected function removeCloneArchiveDirectory(?Server $server, ?string $directory): void
    {
        if (! $server || $directory === null) {
            return;
        }

        try {
            instant_remote_process(['rm -rf '.escapeshellarg($directory)], $server, false);
        } catch (Throwable $e) {
            \Log::warning("Failed to clean up clone directory {$directory} on server {$server->name}: ".$e->getMessage());
        }
    }
}
