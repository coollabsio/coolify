<?php

namespace App\Traits;

use App\Jobs\PrepareFileDownloadJob;
use App\Models\Server;
use App\Services\FileDownloadStorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Backup downloads through the instance download S3 storage: the server
 * uploads the backup to S3 and the browser downloads it from there. Without
 * the storage, when the local file is gone, or when the backup is larger
 * than one S3 PUT, the browser uses the Coolify download route.
 *
 * The view must poll checkDownloads() while $pendingDownloads is not empty
 * and open the URL of each `open-backup-download` window event.
 */
trait PreparesBackupDownloads
{
    private const MAX_PENDING_S3_DOWNLOADS = 3;

    /** @var array<int, string> */
    public array $pendingDownloads = [];

    /**
     * Authorize the user and load the backup from a record scoped to the
     * component before you call this. Pass a null server or file name when
     * the local file is gone; the download route then answers the request.
     */
    protected function prepareBackupDownload(?Server $server, ?string $filename, string $streamUrl): void
    {
        $storage = FileDownloadStorageService::configuredStorage();
        $size = $storage && $server && filled($filename) ? FileDownloadStorageService::hostFileSize($server, $filename) : null;
        if ($size === null || $size > FileDownloadStorageService::MAX_SIZE) {
            $this->dispatch('open-backup-download', url: $streamUrl);

            return;
        }

        if (count($this->pendingDownloads) >= self::MAX_PENDING_S3_DOWNLOADS
            || ! RateLimiter::attempt('file-browser-s3-download:'.auth()->id(), 10, fn () => true, 3600)) {
            $this->dispatch('error', 'Too many downloads are in progress. Wait for them to finish, then try again.');

            return;
        }

        $downloadId = (string) Str::uuid();
        $this->pendingDownloads[] = $downloadId;
        PrepareFileDownloadJob::dispatch($downloadId, auth()->id(), $server->id, null, $filename, false, basename($filename), $storage->id);
        $this->dispatch('info', 'Preparing '.basename($filename).'. The download starts when it is ready.');
    }

    /**
     * Polled while S3 downloads are prepared. Opens each finished link once.
     */
    public function checkDownloads(): void
    {
        foreach ($this->pendingDownloads as $index => $downloadId) {
            $result = Cache::get(PrepareFileDownloadJob::cacheKey($downloadId));
            if (! is_array($result) || ($result['user_id'] ?? null) !== auth()->id()) {
                continue;
            }

            unset($this->pendingDownloads[$index]);
            Cache::forget(PrepareFileDownloadJob::cacheKey($downloadId));
            if ($result['status'] === 'ready') {
                $this->dispatch('open-backup-download', url: $result['url']);
            } else {
                $this->dispatch('error', $result['message']);
            }
        }
        $this->pendingDownloads = array_values($this->pendingDownloads);
    }

    protected function s3BackupDownloadsEnabled(): bool
    {
        return FileDownloadStorageService::configuredStorage() !== null;
    }
}
