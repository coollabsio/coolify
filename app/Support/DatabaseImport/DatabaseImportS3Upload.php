<?php

namespace App\Support\DatabaseImport;

use App\Actions\Database\StartDatabaseImport;
use App\Models\S3Storage;
use App\Support\DatabaseBackupFileValidator;
use Aws\S3\S3Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A backup that the browser uploads directly to an S3 storage of the team, so the file never goes through the
 * Coolify instance. Coolify creates a multipart upload and presigns its parts; the browser PUTs the parts.
 *
 * Every upload has a local marker (storage, key, multipart upload id). The cleanup listener of the import deletes
 * the object after the import, and `cleanup:database-import-uploads` deletes objects and aborts multipart uploads
 * that were not imported within a day.
 */
class DatabaseImportS3Upload
{
    public const KEY_PREFIX = 'coolify-uploads/database-imports/';

    public const MARKER_DIRECTORY = 'upload/s3-imports';

    /**
     * S3 allows 10,000 parts, and parts of at least 5 MiB except the last. 64 MiB gives 160 parts for 10 GiB.
     */
    public const PART_SIZE = 64 * 1024 * 1024;

    /**
     * All part URLs are presigned at the start, so they must stay valid for a slow upload of 10 GiB.
     */
    public const URL_TTL_HOURS = 12;

    /**
     * @param  array{team_id: int, upload_id: string, storage_id: int, key: string, multipart_upload_id: ?string, resource_uuid: string, name: string, size: int}  $marker
     */
    private function __construct(public array $marker) {}

    /**
     * The key prefix of the uploads into one database. A member who can upload backups for this database can
     * import these keys, as they can import the backups of this database.
     */
    public static function resourcePrefix(int $teamId, Model $resource): string
    {
        return self::KEY_PREFIX.$teamId.'/'.$resource->uuid.'/';
    }

    public static function isUploadKeyFor(Model $resource, string $key): bool
    {
        $teamId = $resource->team()?->id;
        if ($teamId === null) {
            return false;
        }

        return preg_match('#^'.preg_quote(self::resourcePrefix($teamId, $resource), '#').'[0-9a-f-]{36}/[^/]+$#', ltrim($key, '/')) === 1;
    }

    /**
     * Keeps the extension, because the import checks it, and only characters that the import accepts in S3 paths.
     */
    public static function objectName(string $filename): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($filename));
        $name = (string) preg_replace('/\.{2,}/', '.', $name);

        return ltrim(substr($name, -120), '.-') ?: 'backup';
    }

    /**
     * Creates the multipart upload and returns the presigned URLs of all parts.
     *
     * @return array{upload: self, partSize: int, parts: list<array{number: int, url: string}>}
     */
    public static function start(S3Storage $storage, Model $resource, int $teamId, string $filename, int $size): array
    {
        if ($size < 1 || $size > StartDatabaseImport::MAX_BYTES) {
            throw new DatabaseImportException('The backup file is empty or exceeds the 10 GiB limit.');
        }
        if (! DatabaseBackupFileValidator::hasAllowedExtension($filename)) {
            throw new DatabaseImportException('Unsupported file type. Allowed extensions: '.implode(', ', DatabaseBackupFileValidator::ALLOWED_EXTENSIONS));
        }
        $name = self::objectName($filename);
        if (! DatabaseBackupFileValidator::hasAllowedExtension($name)) {
            throw new DatabaseImportException('The file name is invalid.');
        }

        $uploadId = (string) Str::uuid();
        $upload = new self([
            'team_id' => $teamId,
            'upload_id' => $uploadId,
            'storage_id' => $storage->id,
            'key' => self::resourcePrefix($teamId, $resource).$uploadId.'/'.$name,
            'multipart_upload_id' => null,
            'resource_uuid' => $resource->uuid,
            'name' => $name,
            'size' => $size,
        ]);
        // Saved before the multipart upload exists, so the cleanup also finds an upload whose id was not saved.
        $upload->save();

        $client = self::client($storage);
        try {
            $multipart = $client->createMultipartUpload(['Bucket' => $storage->bucket, 'Key' => $upload->marker['key']]);
        } catch (Throwable $exception) {
            Storage::delete(self::markerPath($teamId, $uploadId));

            throw $exception;
        }
        $upload->marker['multipart_upload_id'] = (string) $multipart['UploadId'];
        $upload->save();

        $parts = [];
        $count = (int) ceil($size / self::PART_SIZE);
        for ($number = 1; $number <= $count; $number++) {
            $command = $client->getCommand('UploadPart', [
                'Bucket' => $storage->bucket,
                'Key' => $upload->marker['key'],
                'UploadId' => $upload->marker['multipart_upload_id'],
                'PartNumber' => $number,
            ]);
            $parts[] = ['number' => $number, 'url' => (string) $client->createPresignedRequest($command, '+'.self::URL_TTL_HOURS.' hours')->getUri()];
        }

        return ['upload' => $upload, 'partSize' => self::PART_SIZE, 'parts' => $parts];
    }

    /**
     * The upload of one team, or null. The id is a UUID, so it cannot point outside the marker directory.
     */
    public static function find(int $teamId, ?string $uploadId): ?self
    {
        if (! Str::isUuid($uploadId)) {
            return null;
        }

        return self::fromPath(self::markerPath($teamId, $uploadId));
    }

    /**
     * @return list<self>
     */
    public static function staleUploads(int $olderThanTimestamp): array
    {
        $uploads = [];
        foreach (Storage::allFiles(self::MARKER_DIRECTORY) as $path) {
            if (Storage::lastModified($path) < $olderThanTimestamp && ($upload = self::fromPath($path))) {
                $uploads[] = $upload;
            }
        }

        return $uploads;
    }

    private static function fromPath(string $path): ?self
    {
        if (! Storage::exists($path)) {
            return null;
        }
        $marker = json_decode((string) Storage::get($path), true);
        if (! is_array($marker) || ! is_int($marker['team_id'] ?? null) || ! Str::isUuid($marker['upload_id'] ?? null)
            || $path !== self::markerPath($marker['team_id'], $marker['upload_id']) || ! is_string($marker['key'] ?? null)
            || ! str_starts_with($marker['key'], self::KEY_PREFIX.$marker['team_id'].'/')) {
            return null;
        }

        return new self($marker);
    }

    /**
     * Completes the multipart upload and checks the real size. The ETags come from ListParts, so the bucket CORS
     * rule does not have to expose the ETag header to the browser.
     */
    public function complete(): void
    {
        $storage = $this->storage();
        if (! $storage || blank($this->marker['multipart_upload_id'])) {
            throw new DatabaseImportException('The upload was not found. Please upload the file again.');
        }

        $client = self::client($storage);
        $parts = [];
        $marker = null;
        do {
            $page = $client->listParts(array_filter([
                'Bucket' => $storage->bucket,
                'Key' => $this->marker['key'],
                'UploadId' => $this->marker['multipart_upload_id'],
                'PartNumberMarker' => $marker,
            ], fn ($value) => $value !== null));
            foreach ($page['Parts'] ?? [] as $part) {
                $parts[] = ['PartNumber' => (int) $part['PartNumber'], 'ETag' => (string) $part['ETag']];
            }
            $marker = ($page['IsTruncated'] ?? false) ? ($page['NextPartNumberMarker'] ?? null) : null;
        } while ($marker !== null);

        $expected = (int) ceil($this->marker['size'] / self::PART_SIZE);
        if (count($parts) !== $expected) {
            throw new DatabaseImportException('The upload is incomplete. Please upload the file again.');
        }

        $client->completeMultipartUpload([
            'Bucket' => $storage->bucket,
            'Key' => $this->marker['key'],
            'UploadId' => $this->marker['multipart_upload_id'],
            'MultipartUpload' => ['Parts' => $parts],
        ]);
        $this->marker['multipart_upload_id'] = null;
        $this->save();

        // S3 does not sign the content length of a part, so only the stored object shows the real size.
        $size = (int) $client->headObject(['Bucket' => $storage->bucket, 'Key' => $this->marker['key']])['ContentLength'];
        if ($size < 1 || $size > StartDatabaseImport::MAX_BYTES || $size !== $this->marker['size']) {
            $this->discard();

            throw new DatabaseImportException('The uploaded file has an unexpected size or exceeds the 10 GiB limit.');
        }
    }

    /**
     * Aborts the multipart upload or deletes the object, then the marker. A failure is logged and the marker stays,
     * so the cleanup command tries again later.
     */
    public function discard(): bool
    {
        try {
            $storage = $this->storage();
            if ($storage) {
                $client = self::client($storage);
                if (filled($this->marker['multipart_upload_id'])) {
                    $client->abortMultipartUpload(['Bucket' => $storage->bucket, 'Key' => $this->marker['key'], 'UploadId' => $this->marker['multipart_upload_id']]);
                }
                $client->deleteObject(['Bucket' => $storage->bucket, 'Key' => $this->marker['key']]);
            }
            Storage::delete(self::markerPath($this->marker['team_id'], $this->marker['upload_id']));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Could not delete an uploaded database import file', [
                'storage_id' => $this->marker['storage_id'],
                'upload_id' => $this->marker['upload_id'],
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Deletes the upload of a finished import. Only keys below the upload prefix are temporary uploads.
     */
    public static function discardImported(int $storageId, string $key): void
    {
        if (preg_match('#^'.preg_quote(self::KEY_PREFIX, '#').'(\d+)/[^/]+/([0-9a-f-]{36})/[^/]+$#', $key, $matches) !== 1) {
            return;
        }
        $upload = self::find((int) $matches[1], $matches[2]);
        if ($upload && $upload->marker['storage_id'] === $storageId && $upload->marker['key'] === $key) {
            $upload->discard();
        }
    }

    /**
     * Saves the marker again, so the one-day limit of the cleanup counts from the start of the import.
     */
    public function touch(): void
    {
        $this->save();
    }

    public function storage(): ?S3Storage
    {
        return S3Storage::query()->s3Only()->where('team_id', $this->marker['team_id'])->find($this->marker['storage_id']);
    }

    public function belongsTo(Model $resource): bool
    {
        return $this->marker['resource_uuid'] === $resource->uuid;
    }

    private function save(): void
    {
        Storage::put(self::markerPath($this->marker['team_id'], $this->marker['upload_id']), json_encode($this->marker));
    }

    private static function markerPath(int $teamId, string $uploadId): string
    {
        return self::MARKER_DIRECTORY."/{$teamId}/{$uploadId}.json";
    }

    private static function client(S3Storage $storage): S3Client
    {
        return $storage->filesystem()->getClient();
    }
}
