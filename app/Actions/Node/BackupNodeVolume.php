<?php

namespace App\Actions\Node;

use App\Actions\Sentinel\FetchContainerLogs;
use App\Models\Node;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use Aws\S3\S3Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Backs up one cluster volume to S3 (decision 0009). Coolify creates the multipart upload and
 * polls `volume.backup.v1`; each poll signs UploadPart URLs for the next parts that Sentinel
 * needs. Sentinel streams `tar | zstd` of the volume, uploads the parts, and reports their ETags,
 * and Coolify completes the upload. The S3 key never leaves Coolify.
 */
class BackupNodeVolume
{
    use AsAction;

    public const array CAPABILITIES = ['volume.backup.v1', 'volume.restore.v1'];

    public const int POLL_SECONDS = 2;

    /** Part URLs that each poll signs from the part that Sentinel needs next. */
    public const int URL_WINDOW = 32;

    /** A part URL is used within seconds of the poll that signed it; the restore URL at the start of the download. */
    public const int URL_MINUTES = 60;

    /** How often the uploaded size is written to the execution for the UI. */
    public const int PROGRESS_SECONDS = 10;

    /** The default of S3: an upload needs at most this many parts. */
    public const int MAX_PARTS = 10000;

    /** @return array{size: int, sha256: string} */
    public function handle(ScheduledVolumeBackup $backup, ScheduledVolumeBackupExecution $execution, Carbon $deadline): array
    {
        $volume = $backup->backupable;
        if (! $volume instanceof NodeVolume) {
            throw new RuntimeException('The backup target is not a cluster volume.');
        }
        $workload = $volume->workload()->first();
        $node = $volume->node()->first();
        if ($workload === null || $node === null) {
            throw new RuntimeException('The volume has no data yet. Deploy the application first.');
        }
        self::ensureNodeSupportsBackups($node);
        $s3 = self::storage($backup, $workload);
        $key = (string) $execution->filename;
        $client = self::client($s3);

        $uploadId = $client->createMultipartUpload([
            'Bucket' => $s3->bucket,
            'Key' => $key,
            'ContentType' => 'application/zstd',
        ])['UploadId'];
        $request = [
            'server_id' => $node->uuid,
            'backup_id' => $execution->uuid,
            'volume_name' => $volume->podmanName(),
            'stop_container' => $backup->stop_during_backup ? FetchContainerLogs::containerName($workload) : '',
            'stop_timeout_seconds' => $backup->stop_during_backup ? 120 : 0,
        ];

        try {
            $state = $this->poll($request, $client, $s3, $key, $uploadId, 1);
            $progressWrittenAt = now();
            while ($state['state'] === 'running') {
                if (now()->gte($deadline)) {
                    throw new RuntimeException('The backup did not finish within the timeout of the schedule.');
                }
                Sleep::for(self::POLL_SECONDS)->seconds();
                $state = $this->poll($request, $client, $s3, $key, $uploadId, $state['next_part_number']);
                if ($progressWrittenAt->lte(now()->subSeconds(self::PROGRESS_SECONDS))) {
                    $execution->update(['size' => $state['bytes_done']]);
                    $progressWrittenAt = now();
                }
            }
            if ($state['state'] === 'failed') {
                throw new RuntimeException('Sentinel could not back up the volume: '.($state['error'] !== '' ? $state['error'] : 'no reason reported.'));
            }

            $parts = collect($state['parts'])->sortBy('part_number')->values();
            $numbers = $parts->pluck('part_number')->all();
            if ($numbers !== range(1, count($numbers)) || $parts->sum('size_bytes') !== $state['archive_bytes'] || ! preg_match('/^[0-9a-f]{64}$/', $state['sha256'])) {
                throw new RuntimeException('Sentinel reported an incomplete backup.');
            }
            $client->completeMultipartUpload([
                'Bucket' => $s3->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts->map(fn (array $part): array => [
                    'PartNumber' => $part['part_number'],
                    'ETag' => $part['etag'],
                ])->all()],
            ]);
            $stored = (int) $client->headObject(['Bucket' => $s3->bucket, 'Key' => $key])['ContentLength'];
            if ($stored !== $state['archive_bytes']) {
                throw new RuntimeException("S3 stored {$stored} bytes, but Sentinel uploaded {$state['archive_bytes']}.");
            }

            return ['size' => $state['archive_bytes'], 'sha256' => $state['sha256']];
        } catch (Throwable $exception) {
            $this->abort($request, $client, $s3, $key, $uploadId);

            throw new RuntimeException(self::redactUrls($exception->getMessage()), 0, $exception);
        }
    }

    /**
     * The S3 storage of the schedule, checked again at run time: it must belong to the team of the
     * volume, whatever team the schedule records.
     */
    public static function storage(ScheduledVolumeBackup $backup, NodeWorkload $workload): S3Storage
    {
        $s3 = S3Storage::query()
            ->whereKey($backup->s3_storage_id)
            ->where('team_id', $workload->team_id)
            ->first();
        if ($s3 === null) {
            throw new RuntimeException('The S3 storage of this backup does not exist or belongs to another team. Select an S3 storage of the team.');
        }

        return $s3;
    }

    public static function ensureNodeSupportsBackups(Node $node): void
    {
        foreach (self::CAPABILITIES as $capability) {
            $node->ensureReportedCapability($capability);
        }
    }

    public static function client(S3Storage $s3): S3Client
    {
        return $s3->filesystem()->getClient();
    }

    /** A presigned GetObject URL for the restore of an archive. */
    public static function downloadUrl(S3Storage $s3, string $key): string
    {
        $client = self::client($s3);

        return (string) $client->createPresignedRequest(
            $client->getCommand('GetObject', ['Bucket' => $s3->bucket, 'Key' => $key]),
            '+'.self::URL_MINUTES.' minutes',
        )->getUri();
    }

    /** Replaces presigned URLs in text that is stored or shown. */
    public static function redactUrls(string $text): string
    {
        return (string) preg_replace('~https?://\S*[?&]X-Amz-\S*~i', '[presigned URL]', $text);
    }

    /**
     * Sends one poll with fresh URLs from `$nextPart` on.
     *
     * @param  array<string, mixed>  $request
     * @return array{state: string, used_bytes: int, part_size_bytes: int, next_part_number: int, parts: list<array{part_number: int, etag: string, size_bytes: int}>, bytes_done: int, archive_bytes: int, sha256: string, error: string}
     */
    private function poll(array $request, S3Client $client, S3Storage $s3, string $key, string $uploadId, int $nextPart): array
    {
        $partUrls = [];
        if ($nextPart > 0) {
            foreach (range($nextPart, min(self::MAX_PARTS, $nextPart + self::URL_WINDOW - 1)) as $partNumber) {
                $partUrls[] = [
                    'part_number' => $partNumber,
                    'url' => (string) $client->createPresignedRequest($client->getCommand('UploadPart', [
                        'Bucket' => $s3->bucket,
                        'Key' => $key,
                        'UploadId' => $uploadId,
                        'PartNumber' => $partNumber,
                    ]), '+'.self::URL_MINUTES.' minutes')->getUri(),
                ];
            }
        }

        return self::send([...$request, 'part_urls' => $partUrls]);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{state: string, used_bytes: int, part_size_bytes: int, next_part_number: int, parts: list<array{part_number: int, etag: string, size_bytes: int}>, bytes_done: int, archive_bytes: int, sha256: string, error: string}
     */
    private static function send(array $request): array
    {
        $commandId = (string) Str::uuid();
        $data = SendFluxCommand::run('volume.backup', [...$request, 'command_id' => $commandId], 150);
        $validator = Validator::make($data, [
            'command_id' => ['required', 'string', 'in:'.$commandId],
            'state' => ['required', 'in:running,succeeded,failed'],
            'used_bytes' => ['required', 'integer', 'min:0'],
            'part_size_bytes' => ['required', 'integer', 'min:0'],
            'next_part_number' => ['required', 'integer', 'min:0', 'max:'.(self::MAX_PARTS + 1)],
            'parts' => ['present', 'array', 'max:'.self::MAX_PARTS],
            'parts.*.part_number' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PARTS],
            'parts.*.etag' => ['required', 'string', 'max:256'],
            'parts.*.size_bytes' => ['required', 'integer', 'min:0'],
            'bytes_done' => ['required', 'integer', 'min:0'],
            'archive_bytes' => ['required', 'integer', 'min:0'],
            'sha256' => ['nullable', 'string', 'max:64'],
            'error' => ['nullable', 'string', 'max:4000'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid volume backup state.');
        }
        $validated = $validator->validated();

        return [
            'state' => $validated['state'],
            'used_bytes' => (int) $validated['used_bytes'],
            'part_size_bytes' => (int) $validated['part_size_bytes'],
            'next_part_number' => (int) $validated['next_part_number'],
            'parts' => array_map(fn (array $part): array => [
                'part_number' => (int) $part['part_number'],
                'etag' => (string) $part['etag'],
                'size_bytes' => (int) $part['size_bytes'],
            ], $validated['parts']),
            'bytes_done' => (int) $validated['bytes_done'],
            'archive_bytes' => (int) $validated['archive_bytes'],
            'sha256' => (string) ($validated['sha256'] ?? ''),
            'error' => (string) ($validated['error'] ?? ''),
        ];
    }

    /**
     * Cleans up after a worker that stopped during a backup: stops the backup on the Node and
     * aborts every unfinished upload of its key. Best effort.
     */
    public static function abandon(ScheduledVolumeBackup $backup, ScheduledVolumeBackupExecution $execution): void
    {
        $volume = $backup->backupable;
        $workload = $volume instanceof NodeVolume ? $volume->workload()->first() : null;
        if ($workload === null || blank($execution->filename)) {
            return;
        }
        if (($node = $volume->node()->first()) !== null) {
            try {
                self::send([
                    'server_id' => $node->uuid,
                    'backup_id' => $execution->uuid,
                    'volume_name' => $volume->podmanName(),
                    'stop_container' => $backup->stop_during_backup ? FetchContainerLogs::containerName($workload) : '',
                    'stop_timeout_seconds' => $backup->stop_during_backup ? 120 : 0,
                    'abort' => true,
                ]);
            } catch (Throwable) {
            }
        }
        try {
            $s3 = self::storage($backup, $workload);
            $client = self::client($s3);
            $uploads = $client->listMultipartUploads(['Bucket' => $s3->bucket, 'Prefix' => $execution->filename])['Uploads'] ?? [];
            foreach ($uploads as $upload) {
                if (($upload['Key'] ?? null) === $execution->filename) {
                    $client->abortMultipartUpload(['Bucket' => $s3->bucket, 'Key' => $upload['Key'], 'UploadId' => $upload['UploadId']]);
                }
            }
        } catch (Throwable) {
        }
    }

    /**
     * Stops the backup on the Node, which starts a stopped container again, and drops the
     * uploaded parts. Both are best effort: an upload that is not completed stores no object.
     *
     * @param  array<string, mixed>  $request
     */
    private function abort(array $request, S3Client $client, S3Storage $s3, string $key, string $uploadId): void
    {
        try {
            self::send([...$request, 'abort' => true]);
        } catch (Throwable) {
        }
        try {
            $client->abortMultipartUpload(['Bucket' => $s3->bucket, 'Key' => $key, 'UploadId' => $uploadId]);
        } catch (Throwable) {
        }
    }
}
