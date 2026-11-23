<?php

namespace App\Support\DatabaseImport;

use App\Actions\Database\StartDatabaseImport;
use App\Models\S3Storage;
use App\Support\DatabaseBackupFileValidator;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A backup that the browser uploads directly to the S3 storage that the instance admin selected for database
 * imports, so the file does not use disk space on the Coolify instance. Coolify creates a multipart upload and
 * presigns its parts; the browser PUTs the parts.
 *
 * The storage belongs to the root team, so its keys never reach a team's server: the import downloads the file
 * with a presigned URL. Every upload has a local marker (storage, key, multipart upload id). The import cleanup
 * deletes the object after the import, and `cleanup:database-import-uploads` deletes objects and aborts multipart
 * uploads that were not imported within a day.
 */
class DatabaseImportS3Upload
{
    public const KEY_PREFIX = 'coolify-uploads/database-imports/';

    public const MARKER_DIRECTORY = 'upload/s3-imports';

    public const CORS_RULE_ID = 'coolify-database-import-uploads';

    public const LIFECYCLE_RULE_ID = 'coolify-database-import-uploads';

    /**
     * S3 allows 10,000 parts, and parts of at least 5 MiB except the last. 64 MiB gives 160 parts for 10 GiB.
     */
    public const PART_SIZE = 64 * 1024 * 1024;

    /**
     * All part URLs are presigned at the start, so they must stay valid for a slow upload of 10 GiB.
     */
    public const URL_TTL_HOURS = 12;

    /**
     * Uploads of one team that are not imported yet. S3 does not sign the size of a part, so this limits what a
     * team can store in the instance bucket until the cleanup aborts the uploads.
     */
    public const MAX_PENDING_PER_TEAM = 5;

    /**
     * @param  array{team_id: int, upload_id: string, storage_id: int, key: string, multipart_upload_id: ?string, resource_uuid: string, name: string, size: int}  $marker
     */
    private function __construct(public array $marker) {}

    /**
     * The storage that the instance admin selected for uploads of database imports, or null to upload to Coolify.
     */
    public static function configuredStorage(): ?S3Storage
    {
        $storage = self::instanceStorage((int) instanceSettings()->database_import_s3_storage_id);

        return $storage?->is_usable ? $storage : null;
    }

    /**
     * Adds the CORS rule that lets the browser PUT the parts, and a lifecycle rule that removes forgotten uploads,
     * to the bucket. Other rules stay. Some providers do not support these API calls, so a failure is returned as a
     * message for the admin instead of refusing the storage.
     *
     * @return list<string>
     */
    public static function prepareBucket(S3Storage $storage): array
    {
        $client = self::client($storage);
        $problems = [];

        try {
            try {
                $rules = $client->getBucketCors(['Bucket' => $storage->bucket])['CORSRules'] ?? [];
            } catch (S3Exception $e) {
                if ($e->getAwsErrorCode() !== 'NoSuchCORSConfiguration') {
                    throw $e;
                }
                $rules = [];
            }
            $rules = array_values(array_filter($rules, fn (array $rule) => ($rule['ID'] ?? null) !== self::CORS_RULE_ID));
            $rules[] = ['ID' => self::CORS_RULE_ID, 'AllowedOrigins' => [rtrim(base_url(), '/')], 'AllowedMethods' => ['PUT'], 'AllowedHeaders' => ['*'], 'MaxAgeSeconds' => 3600];
            $client->putBucketCors(['Bucket' => $storage->bucket, 'CORSConfiguration' => ['CORSRules' => $rules]]);
        } catch (Throwable $e) {
            $problems[] = 'Coolify could not add the CORS rule to bucket "'.$storage->bucket.'". Add a rule that allows PUT from '.rtrim(base_url(), '/').' in the settings of your S3 provider. Error: '.$e->getMessage();
        }

        try {
            try {
                $rules = $client->getBucketLifecycleConfiguration(['Bucket' => $storage->bucket])['Rules'] ?? [];
            } catch (S3Exception $e) {
                if ($e->getAwsErrorCode() !== 'NoSuchLifecycleConfiguration') {
                    throw $e;
                }
                $rules = [];
            }
            $rules = array_values(array_filter($rules, fn (array $rule) => ($rule['ID'] ?? null) !== self::LIFECYCLE_RULE_ID));
            $rules[] = [
                'ID' => self::LIFECYCLE_RULE_ID,
                'Filter' => ['Prefix' => self::KEY_PREFIX],
                'Status' => 'Enabled',
                'Expiration' => ['Days' => 2],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1],
            ];
            $client->putBucketLifecycleConfiguration(['Bucket' => $storage->bucket, 'LifecycleConfiguration' => ['Rules' => $rules]]);
        } catch (Throwable $e) {
            $problems[] = 'Coolify could not add the auto-delete lifecycle rule to bucket "'.$storage->bucket.'". Coolify still deletes the uploads itself. Error: '.$e->getMessage();
        }

        return $problems;
    }

    /**
     * Keeps the extension, because the import checks it, and only characters that are safe in S3 keys.
     */
    public static function objectName(string $filename): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($filename));
        $name = (string) preg_replace('/\.{2,}/', '.', $name);

        return ltrim(substr($name, -120), '.-') ?: 'backup';
    }

    /**
     * Creates the multipart upload in the configured storage and returns the presigned URLs of all parts.
     *
     * @return array{upload: self, partSize: int, parts: list<array{number: int, url: string}>}
     */
    public static function start(Model $resource, int $teamId, string $filename, int $size): array
    {
        $storage = self::configuredStorage();
        if (! $storage) {
            throw new DatabaseImportException('Uploads to an S3 storage are not enabled on this instance.');
        }
        if ($size < 1 || $size > StartDatabaseImport::MAX_BYTES) {
            throw new DatabaseImportException('The backup file is empty or exceeds the 10 GiB limit.');
        }
        $name = self::objectName($filename);
        if (! DatabaseBackupFileValidator::hasAllowedExtension($filename) || ! DatabaseBackupFileValidator::hasAllowedExtension($name)) {
            throw new DatabaseImportException('Unsupported file type. Allowed extensions: '.implode(', ', DatabaseBackupFileValidator::ALLOWED_EXTENSIONS));
        }
        if (count(Storage::files(self::MARKER_DIRECTORY."/{$teamId}")) >= self::MAX_PENDING_PER_TEAM) {
            throw new DatabaseImportException('Your team has too many uploads that are not imported. Import or remove them, or try again in one day.');
        }

        $uploadId = (string) Str::uuid();
        $upload = new self([
            'team_id' => $teamId,
            'upload_id' => $uploadId,
            'storage_id' => $storage->id,
            'key' => self::KEY_PREFIX."{$teamId}/{$resource->uuid}/{$uploadId}/{$name}",
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
            $upload->deleteMarker();

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
            || $path !== self::markerPath($marker['team_id'], $marker['upload_id']) || ! is_int($marker['storage_id'] ?? null)
            || ! is_string($marker['key'] ?? null) || ! str_starts_with($marker['key'], self::KEY_PREFIX.$marker['team_id'].'/')) {
            return null;
        }

        return new self($marker);
    }

    public function isCompleted(): bool
    {
        return blank($this->marker['multipart_upload_id']);
    }

    /**
     * Completes the multipart upload and checks the real size. The ETags come from ListParts, so the bucket CORS
     * rule does not have to expose the ETag header to the browser.
     */
    public function complete(): void
    {
        $storage = $this->storage();
        if (! $storage || $this->isCompleted()) {
            throw new DatabaseImportException('The upload was not found. Please upload the file again.');
        }

        $client = self::client($storage);
        $parts = [];
        $partMarker = null;
        do {
            $page = $client->listParts(array_filter([
                'Bucket' => $storage->bucket,
                'Key' => $this->marker['key'],
                'UploadId' => $this->marker['multipart_upload_id'],
                'PartNumberMarker' => $partMarker,
            ], fn ($value) => $value !== null));
            foreach ($page['Parts'] ?? [] as $part) {
                $parts[] = ['PartNumber' => (int) $part['PartNumber'], 'ETag' => (string) $part['ETag']];
            }
            $partMarker = ($page['IsTruncated'] ?? false) ? ($page['NextPartNumberMarker'] ?? null) : null;
        } while ($partMarker !== null);

        if (count($parts) !== (int) ceil($this->marker['size'] / self::PART_SIZE)) {
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
        if ($size !== $this->marker['size']) {
            $this->discard();

            throw new DatabaseImportException('The uploaded file has an unexpected size. Please upload it again.');
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
                if (! $this->isCompleted()) {
                    $client->abortMultipartUpload(['Bucket' => $storage->bucket, 'Key' => $this->marker['key'], 'UploadId' => $this->marker['multipart_upload_id']]);
                }
                $client->deleteObject(['Bucket' => $storage->bucket, 'Key' => $this->marker['key']]);
            }
            $this->deleteMarker();

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
     * Saves the marker again, so the one-day limit of the cleanup counts from the start of the import.
     */
    public function touch(): void
    {
        $this->save();
    }

    /**
     * The storage of this upload. It stays the same when the admin selects another storage later.
     */
    public function storage(): ?S3Storage
    {
        return self::instanceStorage($this->marker['storage_id']);
    }

    public function belongsTo(Model $resource): bool
    {
        return $this->marker['resource_uuid'] === $resource->uuid;
    }

    /**
     * Without the is_usable check, so a forgotten upload is still deleted when the storage fails a later test.
     */
    private static function instanceStorage(int $storageId): ?S3Storage
    {
        return S3Storage::query()->s3Only()->whereTeamId(0)->find($storageId);
    }

    private function save(): void
    {
        Storage::put(self::markerPath($this->marker['team_id'], $this->marker['upload_id']), json_encode($this->marker));
    }

    private function deleteMarker(): void
    {
        Storage::delete(self::markerPath($this->marker['team_id'], $this->marker['upload_id']));
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
