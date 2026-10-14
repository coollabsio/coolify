<?php

namespace App\Jobs;

use App\Models\S3Storage;
use App\Services\FileDownloadStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes a file browser download from S3 when its link expires. The bucket
 * lifecycle rule removes anything this job misses after one day.
 */
class DeleteFileDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public int $s3StorageId,
        public string $key,
    ) {}

    public function handle(FileDownloadStorageService $downloads): void
    {
        $storage = S3Storage::find($this->s3StorageId);
        if ($storage && str_starts_with($this->key, FileDownloadStorageService::PREFIX)) {
            $downloads->delete($storage, $this->key);
        }
    }
}
