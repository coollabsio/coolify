<?php

namespace App\Console\Commands;

use App\Actions\Database\StartDatabaseImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CleanupDatabaseImportUploads extends Command
{
    public const MAX_AGE_HOURS = 24;

    protected $signature = 'cleanup:database-import-uploads';

    protected $description = 'Delete staged API database import uploads that were not imported within a day';

    /**
     * Uploads are staged at upload/imports/{team}/{database uuid}/{upload id}/restore. Starting an import
     * copies the file to the server and deletes it while it holds the import lock of the database, so an
     * upload is only deleted while this command holds that lock too.
     */
    public function handle(): int
    {
        $deleted = 0;
        $cutoff = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();

        foreach (Storage::directories('upload/imports') as $teamDirectory) {
            foreach (Storage::directories($teamDirectory) as $databaseDirectory) {
                foreach (Storage::directories($databaseDirectory) as $uploadDirectory) {
                    if ($this->lastModified($uploadDirectory) < $cutoff && $this->deleteUnlessImporting($uploadDirectory, basename($databaseDirectory))) {
                        $deleted++;
                    }
                }
                $this->deleteIfEmpty($databaseDirectory);
            }
            $this->deleteIfEmpty($teamDirectory);
        }

        $this->info("Deleted {$deleted} staged database import upload(s).");

        return self::SUCCESS;
    }

    private function lastModified(string $directory): int
    {
        $timestamps = array_map(fn (string $file): int => Storage::lastModified($file), Storage::allFiles($directory));

        return $timestamps === [] ? (int) filemtime(Storage::path($directory)) : max($timestamps);
    }

    private function deleteUnlessImporting(string $directory, string $databaseUuid): bool
    {
        $lock = Cache::lock(StartDatabaseImport::lockKey($databaseUuid), 60);
        if (! $lock->get()) {
            return false;
        }

        try {
            return Storage::deleteDirectory($directory);
        } finally {
            $lock->release();
        }
    }

    private function deleteIfEmpty(string $directory): void
    {
        if (Storage::allFiles($directory) === [] && Storage::allDirectories($directory) === []) {
            Storage::deleteDirectory($directory);
        }
    }
}
