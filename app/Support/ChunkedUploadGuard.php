<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pion\Laravel\ChunkUpload\Handler\DropZoneUploadHandler;
use Pion\Laravel\ChunkUpload\Handler\SingleUploadHandler;

/**
 * Limits chunked uploads to the disk of the Coolify instance. The client declares the total size, so
 * the bytes that arrive count against an hourly budget of the team. The chunks of an unfinished upload
 * stay on disk for an hour (uploads:clear), so the budget also limits them.
 */
final class ChunkedUploadGuard
{
    public const MAX_TEAM_BYTES_PER_HOUR = 20 * 1024 ** 3;

    /** Finished uploads that a team keeps staged. Staging one more deletes the oldest. */
    public const MAX_STAGED_UPLOADS_PER_TEAM = 3;

    /**
     * Counts the uploaded file or chunk of the request against the budget of the team.
     *
     * @return string|null the error when the team used up its budget
     */
    public static function consume(Request $request, int $teamId): ?string
    {
        $key = "upload-bytes:{$teamId}";
        $bytes = (int) $request->file('file')?->getSize();
        if (RateLimiter::attempts($key) + $bytes > self::MAX_TEAM_BYTES_PER_HOUR) {
            return 'This team uploaded more than '.(self::MAX_TEAM_BYTES_PER_HOUR / 1024 ** 3).' GiB in the last hour. Try again in '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minutes.';
        }
        RateLimiter::increment($key, 3600, $bytes);

        return null;
    }

    /**
     * DropZone chunks, or one file. Other chunk protocols of the library are not accepted.
     *
     * @return class-string
     */
    public static function handler(Request $request): string
    {
        return DropZoneUploadHandler::canBeUsedForRequest($request) ? DropZoneUploadHandler::class : SingleUploadHandler::class;
    }
}
