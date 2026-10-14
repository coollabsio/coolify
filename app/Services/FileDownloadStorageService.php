<?php

namespace App\Services;

use App\Models\S3Storage;
use App\Models\Server;
use App\Rules\SafeWebhookUrl;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Optional S3 path for file browser downloads. The managed server uploads
 * straight to S3 with a presigned URL and the browser downloads from S3, so
 * the file never passes through the Coolify host.
 */
class FileDownloadStorageService
{
    public const PREFIX = 'coolify-file-downloads/';

    public const LIFECYCLE_RULE_ID = 'coolify-file-downloads';

    /**
     * S3 checks the link time when a download starts, so a download that has
     * started finishes after the link expires. Nobody can start a new one.
     */
    public const LINK_TTL_MINUTES = 15;

    /** An expired object cannot be downloaded, so it can stay until the delete job runs. */
    public const OBJECT_TTL_HOURS = 6;

    /** A single S3 PUT accepts at most 5 GiB. */
    public const MAX_SIZE = 5 * 1024 * 1024 * 1024;

    public static function configuredStorage(): ?S3Storage
    {
        $storageId = instanceSettings()->file_download_s3_storage_id;
        if (! $storageId) {
            return null;
        }

        return S3Storage::query()->whereTeamId(0)->where('is_usable', true)->find($storageId);
    }

    public static function newObjectKey(): string
    {
        return self::PREFIX.Str::random(48);
    }

    /**
     * Add or replace the rule that deletes download objects after one day.
     * S3 lifecycle rules have a one-day minimum, so this is only a safety net
     * for the six-hour delete job. Other rules on the bucket are kept.
     *
     * @throws RuntimeException when the bucket does not accept the rule
     */
    public function ensureLifecycleRule(S3Storage $storage): void
    {
        try {
            $client = $storage->filesystem()->getClient();
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
            ];

            $client->putBucketLifecycleConfiguration([
                'Bucket' => $storage->bucket,
                'LifecycleConfiguration' => ['Rules' => $rules],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not add the auto-delete lifecycle rule to bucket "'.$storage->bucket.'". The S3 provider must support lifecycle rules, and the key must be allowed to change them. Error: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Download links are only safe while object keys stay secret. Refuse a
     * bucket that lets anonymous users list its objects, and an endpoint that
     * only Coolify can reach (servers upload to it, browsers download from it).
     *
     * @throws RuntimeException when the endpoint is internal, unreachable or listable
     */
    public function ensureBucketIsPrivate(S3Storage $storage): void
    {
        if ($storage->trustedInternalHosts() !== []) {
            throw new RuntimeException('"'.$storage->name.'" uses an internal Docker host name. Your servers and browsers cannot reach it. Use an S3 endpoint with a public or LAN address.');
        }

        try {
            $this->anonymousClient($storage)->listObjectsV2(['Bucket' => $storage->bucket, 'Prefix' => self::PREFIX, 'MaxKeys' => 1]);
        } catch (S3Exception $e) {
            if ($e->getResponse() === null && $e->getAwsErrorCode() === null) {
                throw new RuntimeException('Could not connect to "'.$storage->name.'". Check the endpoint. Your servers and browsers must reach it too. Error: '.$e->getMessage(), previous: $e);
            }

            return;
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not connect to "'.$storage->name.'". Check the endpoint. Your servers and browsers must reach it too. Error: '.$e->getMessage(), previous: $e);
        }

        throw new RuntimeException('Bucket "'.$storage->bucket.'" allows anonymous listing. Make the bucket private before you use it for file downloads.');
    }

    protected function anonymousClient(S3Storage $storage): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $storage->region,
            'endpoint' => $storage->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => false,
            'retries' => 0,
            'http' => array_merge(SafeWebhookUrl::httpClientOptions($storage->endpoint, $storage->trustedInternalHosts()), [
                'connect_timeout' => 15,
                'timeout' => 15,
            ]),
        ]);
    }

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function uploadUrl(S3Storage $storage, string $key, string $downloadName): array
    {
        $upload = $storage->filesystem()->temporaryUploadUrl($key, now()->addHour(), [
            'ContentDisposition' => self::contentDisposition($downloadName),
        ]);

        return [
            'url' => $upload['url'],
            'headers' => collect($upload['headers'])
                ->map(fn ($value) => is_array($value) ? implode(', ', $value) : (string) $value)
                ->except(['Host', 'host'])
                ->all(),
        ];
    }

    public function downloadUrl(S3Storage $storage, string $key): string
    {
        return $storage->filesystem()->temporaryUrl($key, now()->addMinutes(self::LINK_TTL_MINUTES));
    }

    public function delete(S3Storage $storage, string $key): void
    {
        $storage->filesystem()->delete($key);
    }

    /**
     * Size in bytes of a file on the server host (for example a backup), or
     * null when the file does not exist.
     */
    public static function hostFileSize(Server $server, string $path): ?int
    {
        $size = trim((string) instant_remote_process([self::buildHostFileSizeCommand($path)], $server));

        return ctype_digit($size) ? (int) $size : null;
    }

    public static function buildHostFileSizeCommand(string $path): string
    {
        validateShellSafePath($path, 'download path');
        $escaped = escapeshellarg($path);

        return 'sh -c '.escapeshellarg('if [ -f '.$escaped.' ]; then stat -L -c %s '.$escaped.'; fi');
    }

    /**
     * PUT a file on the server host to a presigned S3 URL.
     *
     * @param  array<string, string>  $headers
     */
    public static function buildHostFileUploadCommand(string $path, string $url, array $headers): string
    {
        validateShellSafePath($path, 'download path');

        return 'sh -c '.escapeshellarg('curl -fsS --retry 2 '.self::curlHeaderArgs($headers).' -T '.escapeshellarg($path).' '.escapeshellarg($url));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function curlHeaderArgs(array $headers): string
    {
        return collect($headers)
            ->map(fn (string $value, string $name) => '-H '.escapeshellarg("{$name}: {$value}"))
            ->implode(' ');
    }

    public static function contentDisposition(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';

        return 'attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($name);
    }
}
