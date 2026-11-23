<?php

namespace App\Services;

use App\Models\S3Storage;
use Aws\S3\Exception\S3Exception;
use Throwable;

/**
 * Optional S3 storage for browser uploads (database imports, static site archives). The browser uploads straight
 * to the bucket with presigned URLs, so the files do not use disk space on the Coolify instance. Each feature keeps
 * its objects below its own folder of PREFIX and deletes them when it is done with them.
 */
class UploadStorageService
{
    public const PREFIX = 'coolify-uploads/';

    public const CORS_RULE_ID = 'coolify-uploads';

    public const LIFECYCLE_RULE_ID = 'coolify-uploads';

    /**
     * The storage that the instance admin selected for uploads, or null to upload to the Coolify instance.
     */
    public static function configuredStorage(): ?S3Storage
    {
        $storage = self::findStorage((int) instanceSettings()->upload_s3_storage_id);

        return $storage?->is_usable ? $storage : null;
    }

    /**
     * An upload storage of the instance (root team), also when it is no longer selected or usable, so features can
     * still delete the objects of earlier uploads.
     */
    public static function findStorage(int $storageId): ?S3Storage
    {
        return S3Storage::query()->s3Only()->whereTeamId(0)->find($storageId);
    }

    /**
     * Adds the CORS rule that lets browsers PUT to the bucket, and a lifecycle rule that removes forgotten uploads.
     * Other rules stay. Some providers do not support these API calls, so a failure is returned as a message for the
     * admin instead of refusing the storage.
     *
     * @return list<string>
     */
    public function prepareBucket(S3Storage $storage): array
    {
        $client = $storage->filesystem()->getClient();
        $origin = rtrim(base_url(), '/');
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
            $ownRule = ['AllowedOrigins' => [$origin], 'AllowedMethods' => ['PUT'], 'AllowedHeaders' => ['*']];
            // Some providers drop the rule ID, so an equal rule also counts as the rule of Coolify.
            $rules = array_values(array_filter($rules, fn (array $rule) => ($rule['ID'] ?? null) !== self::CORS_RULE_ID
                && array_intersect_key($rule, $ownRule) != $ownRule));
            $rules[] = ['ID' => self::CORS_RULE_ID, ...$ownRule, 'MaxAgeSeconds' => 3600];
            $client->putBucketCors(['Bucket' => $storage->bucket, 'CORSConfiguration' => ['CORSRules' => $rules]]);
        } catch (Throwable $e) {
            $problems[] = 'Coolify could not add the CORS rule to bucket "'.$storage->bucket.'". Add a rule that allows PUT from '.$origin.' in the settings of your S3 provider. Error: '.$e->getMessage();
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
                'Filter' => ['Prefix' => self::PREFIX],
                'Status' => 'Enabled',
                'Expiration' => ['Days' => 1],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1],
            ];
            $client->putBucketLifecycleConfiguration(['Bucket' => $storage->bucket, 'LifecycleConfiguration' => ['Rules' => $rules]]);
        } catch (Throwable $e) {
            $problems[] = 'Coolify could not add the auto-delete lifecycle rule to bucket "'.$storage->bucket.'". Coolify still deletes the uploads itself. Error: '.$e->getMessage();
        }

        return $problems;
    }
}
