<?php

namespace App\Console\Commands;

use App\Actions\Database\StartDatabaseImport;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Support\DatabaseImport\DatabaseImportS3Upload;
use App\Support\StaticSiteArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CleanupDatabaseImportUploads extends Command
{
    public const MAX_AGE_HOURS = 24;

    protected $signature = 'cleanup:database-import-uploads';

    protected $description = 'Delete staged database import uploads and static site archives (on the instance or in S3) that were not used within a day';

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

        // Browser uploads to S3: the marker is saved again when an import starts, so a day counts from that start.
        foreach (DatabaseImportS3Upload::staleUploads($cutoff) as $upload) {
            if ($this->discardUnlessImporting($upload)) {
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} staged database import upload(s).");

        // Static site archives are staged at upload/static-archives/{team}/{upload id}/archive, or in an S3 storage
        // with the reference s3.json there. Publishing one moves it away.
        $deletedArchives = 0;
        foreach (Storage::directories('upload/static-archives') as $teamDirectory) {
            foreach (Storage::directories($teamDirectory) as $uploadDirectory) {
                if ($this->lastModified($uploadDirectory) < $cutoff) {
                    StaticSiteArchive::deleteStagedUpload($uploadDirectory);
                    $deletedArchives++;
                }
            }
            $this->deleteIfEmpty($teamDirectory);
        }
        $this->info("Deleted {$deletedArchives} staged static site archive upload(s).");

        // A deployment deletes its archive at applications/{uuid}/static-archives/{deployment uuid} (or the S3
        // reference {deployment uuid}.json) when it copies it to the server. Archives of deployments that were
        // cancelled or never ran stay behind.
        $orphans = collect(Storage::directories('applications'))
            ->flatMap(fn (string $directory): array => Storage::files("{$directory}/static-archives"))
            ->filter(fn (string $file): bool => Storage::lastModified($file) < $cutoff)
            ->mapWithKeys(fn (string $file): array => [$file => basename($file, '.json')]);
        $pending = ApplicationDeploymentQueue::query()
            ->whereIn('deployment_uuid', $orphans->values()->all())
            ->whereIn('status', [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value])
            ->pluck('deployment_uuid')
            ->all();
        $deletedOrphans = 0;
        foreach ($orphans->reject(fn (string $deploymentUuid): bool => in_array($deploymentUuid, $pending, true)) as $file => $deploymentUuid) {
            $application = Application::query()->where('uuid', basename(dirname($file, 2)))->first();
            if ($application) {
                StaticSiteArchive::deleteDeploymentArchive($application, $deploymentUuid);
            } else {
                Storage::delete($file);
            }
            $deletedOrphans++;
        }
        $this->info("Deleted {$deletedOrphans} static site archive(s) of deployments that did not run.");

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

    private function discardUnlessImporting(DatabaseImportS3Upload $upload): bool
    {
        $lock = Cache::lock(StartDatabaseImport::lockKey($upload->marker['resource_uuid']), 60);
        if (! $lock->get()) {
            return false;
        }

        try {
            return $upload->discard();
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
