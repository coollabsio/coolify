<?php

namespace App\Traits;

use App\Models\S3Storage;
use App\Services\FileDownloadStorageService;
use App\Support\StreamsDownloads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use League\Flysystem\WhitespacePathNormalizer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * S3 copies of a backup execution, one per destination. The `s3_uploaded` and `s3_storage_deleted` columns of the
 * execution summarize them.
 */
trait HasS3Replicas
{
    abstract public function s3Replicas(): HasMany;

    /**
     * Deletes the backup file from every destination that still has a copy.
     */
    public function deleteS3Copies(): void
    {
        $replicas = $this->s3Replicas()
            ->with('s3')
            ->where('s3_uploaded', true)
            ->where('s3_storage_deleted', false)
            ->get();

        try {
            foreach ($replicas as $replica) {
                if (! $replica->s3) {
                    throw new \RuntimeException('The S3 storage used by an existing backup is unavailable.');
                }
                if (filled($this->filename)) {
                    deleteBackupsS3($this->filename, $replica->s3);
                }
                $replica->update(['s3_storage_deleted' => true]);
            }
        } finally {
            $this->refreshS3Summary();
        }
    }

    public function hasLiveS3Copies(): bool
    {
        return $this->s3Replicas()->where('s3_uploaded', true)->where('s3_storage_deleted', false)->exists();
    }

    /**
     * Whether a local file or a live S3 copy is left to download.
     */
    public function isDownloadable(): bool
    {
        return ! $this->local_storage_deleted
            || $this->s3Replicas->contains(fn ($replica): bool => $replica->s3_uploaded === true && $replica->s3_storage_deleted === false);
    }

    /**
     * A download of the first live copy in a usable storage of the given team, the primary storage first: a redirect
     * to a short-lived presigned URL for S3, else a stream through Coolify. Null when no such copy exists.
     */
    public function s3Download(int $teamId, ?int $primaryStorageId): ?Response
    {
        if (blank($this->filename)) {
            return null;
        }

        $replicas = $this->s3Replicas()
            ->with('s3')
            ->where('s3_uploaded', true)
            ->where('s3_storage_deleted', false)
            ->whereHas('s3', fn (Builder $query) => $query->where('team_id', $teamId)->where('is_usable', true))
            ->orderBy('id')
            ->get()
            ->sortByDesc(fn ($replica): bool => $replica->s3_storage_id === $primaryStorageId);

        foreach ($replicas as $replica) {
            if (! $replica->s3->isS3()) {
                $download = $this->streamedDownload($replica->s3);
                if ($download) {
                    return $download;
                }

                continue;
            }

            // The upload copies the file to "<bucket><backup directory>/", so the object key is the local file path.
            return redirect()->away($replica->s3->filesystem()->temporaryUrl(
                $this->filename,
                now()->addMinutes(FileDownloadStorageService::LINK_TTL_MINUTES),
                ['ResponseContentDisposition' => FileDownloadStorageService::contentDisposition(basename($this->filename))],
            ));
        }

        return null;
    }

    /**
     * A download through Coolify, or null when the storage is unreachable or has no such file. The response sends
     * only the size that the storage reported, so the browser sees a cut-off download as failed.
     */
    private function streamedDownload(S3Storage $storage): ?StreamedResponse
    {
        $path = (new WhitespacePathNormalizer)->normalizePath($this->filename);

        try {
            $disk = $storage->filesystem();
            $adapter = $disk->getAdapter();
            if (! $adapter instanceof StreamsDownloads) {
                return null;
            }
            $size = $disk->size($path);
        } catch (Throwable) {
            return null;
        }

        return response()->streamDownload(function () use ($adapter, $path, $size): void {
            $output = fopen('php://output', 'wb');

            try {
                $adapter->download($path, $output, $size);
            } finally {
                fclose($output);
            }
        }, basename($path), ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $size]);
    }

    /**
     * Writes the replica states to the summary columns: uploaded when every copy uploaded, deleted when every uploaded
     * copy is deleted.
     */
    public function refreshS3Summary(): void
    {
        $replicas = $this->s3Replicas()->get();
        if ($replicas->isEmpty()) {
            return;
        }

        $uploaded = $replicas->where('s3_uploaded', true);

        $this->update([
            's3_uploaded' => $replicas->every(fn ($replica): bool => $replica->s3_uploaded === true),
            's3_storage_deleted' => $uploaded->isNotEmpty() && $uploaded->every(fn ($replica): bool => $replica->s3_storage_deleted),
        ]);
    }

    /**
     * Executions that have no S3 copy left to keep.
     */
    public function scopeWithoutLiveS3Copies(Builder $query): Builder
    {
        return $query->whereDoesntHave('s3Replicas', fn (Builder $query) => $query
            ->where('s3_uploaded', true)
            ->where('s3_storage_deleted', false));
    }
}
