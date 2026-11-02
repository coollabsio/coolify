<?php

namespace App\Jobs;

use App\Models\S3Storage;
use App\Models\Server;
use App\Services\ContainerFilesystemService;
use App\Services\FileDownloadStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a download from the managed server to the instance download S3
 * storage and stores a presigned link for the Livewire component to open.
 * The source is a path in $container, or a file on the server host (for
 * example a backup) when $container is null.
 */
class PrepareFileDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3700;

    public function __construct(
        public string $downloadId,
        public int $userId,
        public int $serverId,
        public ?string $container,
        public string $path,
        public bool $isDir,
        public string $downloadName,
        public int $s3StorageId,
        public bool $useHelper = false,
    ) {}

    public static function cacheKey(string $downloadId): string
    {
        return 'file-browser-s3-download:'.$downloadId;
    }

    public function handle(FileDownloadStorageService $downloads): void
    {
        try {
            $storage = S3Storage::query()->whereTeamId(0)->where('is_usable', true)->find($this->s3StorageId);
            if (! $storage) {
                throw new \RuntimeException('The file download storage is not available.');
            }
            $server = Server::findOrFail($this->serverId);

            $key = FileDownloadStorageService::newObjectKey();
            // Queue the delete first, so a partial upload is removed as well.
            DeleteFileDownloadJob::dispatch($storage->id, $key)->delay(now()->addHours(FileDownloadStorageService::OBJECT_TTL_HOURS));

            $upload = $downloads->uploadUrl($storage, $key, $this->downloadName);
            if ($this->container === null) {
                instant_remote_process([FileDownloadStorageService::buildHostFileUploadCommand($this->path, $upload['url'], $upload['headers'])], $server, timeout: 3600);
            } else {
                (new ContainerFilesystemService($server, $this->container, $this->useHelper))->uploadToS3($this->path, $this->isDir, $upload['url'], $upload['headers']);
            }

            $this->store(['status' => 'ready', 'url' => $downloads->downloadUrl($storage, $key)]);
        } catch (\Throwable $e) {
            // The message can contain the S3 endpoint or a presigned URL; keep it in the log only.
            Log::warning('S3 download failed', ['download_id' => $this->downloadId, 'error' => $e->getMessage()]);
            $this->store(['status' => 'failed', 'message' => 'Download failed. The file could not be uploaded to the download storage. Try again, or ask your administrator to check the storage settings.']);
        }
    }

    /**
     * @param  array<string, string>  $result
     */
    private function store(array $result): void
    {
        Cache::put(self::cacheKey($this->downloadId), ['user_id' => $this->userId, ...$result], now()->addMinutes(FileDownloadStorageService::LINK_TTL_MINUTES));
    }
}
