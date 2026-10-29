<?php

namespace App\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Laravel writes the config and route cache files in place: the file is empty until the write ends.
 * PHP-FPM already serves requests while app:init caches them, and OPcache (validate_timestamps=0)
 * keeps an empty file it read, so every request fails until a restart (#12120).
 */
class AtomicFilesystem extends Filesystem
{
    /**
     * Write to a temporary file in the same directory, then rename it over the target.
     * Readers see the old or the new complete file. When the write fails, the old file stays.
     */
    public function put($path, $contents, $lock = false)
    {
        $directory = dirname($path);
        $tempPath = @tempnam($directory, basename($path));

        // tempnam() falls back to the system temp directory, where rename() is not atomic.
        if ($tempPath === false || dirname($tempPath) !== realpath($directory)) {
            if ($tempPath !== false) {
                @unlink($tempPath);
            }

            throw new RuntimeException("Could not create a temporary file to write [{$path}].");
        }

        $bytes = file_put_contents($tempPath, $contents);
        if ($bytes !== strlen($contents)) {
            @unlink($tempPath);

            throw new RuntimeException("Could not write [{$path}].");
        }

        // tempnam() creates the file with 0600. Use the permissions file_put_contents() would give.
        @chmod($tempPath, 0666 & ~umask());

        if (! rename($tempPath, $path)) {
            @unlink($tempPath);

            throw new RuntimeException("Could not replace [{$path}].");
        }

        return $bytes;
    }
}
