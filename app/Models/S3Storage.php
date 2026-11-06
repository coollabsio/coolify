<?php

namespace App\Models;

use App\Helpers\SshMultiplexingHelper;
use App\Helpers\SshRetryHandler;
use App\Rules\SafeWebhookUrl;
use App\Rules\ValidS3BucketName;
use App\Traits\Auditable;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class S3Storage extends BaseModel
{
    use Auditable, HasFactory, HasSafeStringAttribute;

    private const CONNECTION_TIMEOUT_SECONDS = 15;

    private const REQUEST_TIMEOUT_SECONDS = 15;

    /**
     * Endpoint host suffixes of providers that reject path-style URLs: Alibaba Cloud OSS, Tencent Cloud COS
     * (buckets created after 2024-01-01), and Volcengine / BytePlus TOS.
     */
    private const VIRTUAL_HOSTED_STYLE_HOST_SUFFIXES = ['aliyuncs.com', 'myqcloud.com', 'volces.com', 'bytepluses.com'];

    /**
     * Parts of the errors that these providers return for a path-style request.
     */
    private const PATH_STYLE_REJECTION_ERRORS = ['secondleveldomainforbidden', 'pathstyledomainforbidden', 'virtual hosted style', 'virtual-hosted style', 'virtual-styled'];

    /**
     * Waits before the second and the third attempt of an upload with the MinIO client.
     */
    private const MINIO_CLIENT_UPLOAD_RETRY_DELAYS_MS = [10_000, 30_000];

    /**
     * The most parts that an upload with the MinIO client uses. Most providers accept 10000 parts, but some, like
     * Scaleway, accept only 1000. The MinIO client sizes the parts for 10000 parts (16 MiB for files up to about
     * 156 GiB), so without a part size, files larger than about 15.6 GiB fail on these providers.
     */
    private const MINIO_CLIENT_MAX_UPLOAD_PARTS = 1000;

    /**
     * The smallest part size of an upload with the MinIO client. It is also the default part size of the MinIO client.
     */
    private const MINIO_CLIENT_MIN_PART_SIZE_BYTES = 16 * 1024 * 1024;

    /**
     * How long the presigned URL of a curl transfer is valid. S3 checks it when the request starts, so it must
     * cover the wait for the earlier transfers and the retries of the backup job.
     */
    private const CURL_TRANSFER_URL_TTL_HOURS = 24;

    private const CURL_TRANSFER_CONFIG_PATH = '/tmp/s3-transfer.curl';

    /**
     * The exception code of runSshProcess() when a remote command times out.
     */
    private const REMOTE_COMMAND_TIMEOUT_EXIT_CODE = 124;

    /**
     * How long the helper container waits for a TCP connection to one IP of the endpoint.
     */
    private const RESOLVE_PROBE_TIMEOUT_SECONDS = 5;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'region',
        'key',
        'secret',
        'bucket',
        'endpoint',
        'use_path_style_endpoint',
        'skip_tls_verification',
        'is_usable',
        'unusable_email_sent',
    ];

    protected $hidden = [
        'key',
        'secret',
    ];

    protected $casts = [
        'is_usable' => 'boolean',
        'use_path_style_endpoint' => 'boolean',
        'skip_tls_verification' => 'boolean',
        'key' => 'encrypted',
        'secret' => 'encrypted',
    ];

    /**
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Trim whitespace from credentials before saving to prevent
        // "Malformed Access Key Id" errors from accidental whitespace in pasted values.
        // Note: We use the saving event instead of Attribute mutators because key/secret
        // use Laravel's 'encrypted' cast. Attribute mutators fire before casts, which
        // would cause issues with the encryption/decryption cycle.
        static::saving(function (S3Storage $storage) {
            if ($storage->key !== null) {
                $storage->key = trim($storage->key);
            }
            if ($storage->secret !== null) {
                $storage->secret = trim($storage->secret);
            }
        });

        static::deleting(function (S3Storage $storage) {
            $storage->detachFromBackups();
        });
    }

    /**
     * Removes this storage from every backup schedule and marks the copies in it as deleted. A schedule moves its
     * primary destination to a remaining storage, or stops S3 backups when none remains.
     */
    public function detachFromBackups(): void
    {
        DB::transaction(function (): void {
            foreach ([ScheduledDatabaseBackup::class, ScheduledVolumeBackup::class] as $backupModel) {
                $backupModel::query()
                    ->usingS3Storage($this->id)
                    ->lazyById()
                    ->each(function (ScheduledDatabaseBackup|ScheduledVolumeBackup $backup): void {
                        $backup->syncS3Storages($backup->s3Storages()->whereKeyNot($this->id)->pluck('s3_storages.id')->all());
                        if ($backup->s3_storage_id === null && $backup->save_s3) {
                            $backup->update(['save_s3' => false]);
                        }
                    });
            }

            foreach ([DatabaseBackupS3Replica::class, VolumeBackupS3Replica::class] as $replicaModel) {
                $replicaModel::query()
                    ->with('execution')
                    ->where('s3_storage_id', $this->id)
                    ->where('s3_storage_deleted', false)
                    ->lazyById()
                    ->each(function (DatabaseBackupS3Replica|VolumeBackupS3Replica $replica): void {
                        $replica->update(['s3_storage_deleted' => true]);
                        $replica->execution?->refreshS3Summary();
                    });
            }

            ScheduledVolumeBackupExecution::query()
                ->where('s3_cleanup_pending', true)
                ->whereHas('s3Replicas', fn (Builder $query) => $query->where('s3_storage_id', $this->id))
                ->whereDoesntHave('s3Replicas', fn (Builder $query) => $query
                    ->where('s3_storage_deleted', false)
                    ->where(fn (Builder $query) => $query->whereNull('s3_uploaded')->orWhere('s3_uploaded', false)))
                ->update(['s3_cleanup_pending' => false]);
        });
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return S3Storage::whereTeamId(currentTeam()->id)->select($selectArray->all())->orderBy('name');
    }

    public static function ownedByCurrentTeamAPI(int $teamId, array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return S3Storage::whereTeamId($teamId)->select($selectArray->all())->orderBy('name');
    }

    public function isUsable()
    {
        return $this->is_usable;
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function scheduledBackups()
    {
        return $this->hasMany(ScheduledDatabaseBackup::class, 's3_storage_id');
    }

    public function scheduledVolumeBackups()
    {
        return $this->hasMany(ScheduledVolumeBackup::class, 's3_storage_id');
    }

    public function awsUrl()
    {
        return "{$this->endpoint}/{$this->bucket}";
    }

    protected function path(): Attribute
    {
        return Attribute::make(
            set: function (?string $value) {
                if ($value === null || $value === '') {
                    return null;
                }

                return str($value)->trim()->start('/')->value();
            }
        );
    }

    /**
     * Trim whitespace from endpoint to prevent malformed URLs.
     */
    protected function endpoint(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? trim($value) : null,
        );
    }

    /**
     * Trim whitespace from bucket name to prevent connection errors.
     */
    protected function bucket(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? trim($value) : null,
        );
    }

    /**
     * Trim whitespace from region to prevent connection errors.
     */
    protected function region(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? trim($value) : null,
        );
    }

    public function testConnection(bool $shouldSave = false)
    {
        try {
            $validator = Validator::make(
                [
                    'endpoint' => $this['endpoint'],
                    'bucket' => $this['bucket'],
                ],
                [
                    'endpoint' => ['required', new SafeWebhookUrl(trustedInternalHosts: $this->trustedInternalHosts())],
                    'bucket' => ['required', new ValidS3BucketName],
                ],
            );
            $validator->fails();
            if ($validator->errors()->has('endpoint')) {
                throw new \RuntimeException('S3 endpoint is not allowed: '.$validator->errors()->first('endpoint'));
            }
            if ($validator->errors()->has('bucket')) {
                throw new \RuntimeException('S3 bucket name is not allowed: '.$validator->errors()->first('bucket'));
            }

            $this->listFilesWithAddressingStyleFallback();

            $this->unusable_email_sent = false;
            $this->is_usable = true;
        } catch (\Throwable $e) {
            $exception = $this->toUserFriendlyConnectionException($e);
            $this->is_usable = false;
            if ($this->unusable_email_sent === false && is_transactional_emails_enabled()) {
                try {
                    $mail = new MailMessage;
                    $mail->subject('Coolify: S3 Storage Connection Error');
                    $mail->view('emails.s3-connection-error', ['name' => $this->name, 'reason' => $e->getMessage(), 'url' => base_url().'/storages/'.$this->uuid]);

                    // Load the team with its members and their roles explicitly
                    $team = $this->team()->with(['members' => function ($query) {
                        $query->withPivot('role');
                    }])->first();

                    // Get admins directly from the pivot relationship for this specific team
                    $users = $team->members()->wherePivotIn('role', ['admin', 'owner'])->get(['users.id', 'users.email']);
                    foreach ($users as $user) {
                        send_user_an_email($mail, $user->email, asNotification: true);
                    }
                    $this->unusable_email_sent = true;
                } catch (\Throwable $emailException) {
                    \Log::warning('Failed to send S3 connection error notification: '.$emailException->getMessage());
                }
            }

            throw $exception;
        } finally {
            if ($shouldSave) {
                $this->save();
            }
        }
    }

    public function filesystem(): FilesystemAdapter
    {
        return Storage::build([
            'driver' => 's3',
            'region' => $this['region'],
            'key' => $this['key'],
            'secret' => $this['secret'],
            'bucket' => $this['bucket'],
            'endpoint' => $this['endpoint'],
            'use_path_style_endpoint' => $this->usesPathStyleEndpoint(),
            'http' => array_merge($this->httpClientOptions(), [
                'connect_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]),
        ]);
    }

    /**
     * The Guzzle options of every request from Coolify to the endpoint: the DNS pin of SafeWebhookUrl, and the TLS
     * certificate check, which a storage with a self-signed or private CA certificate can turn off.
     *
     * @return array<string, mixed>
     */
    public function httpClientOptions(): array
    {
        return array_merge(SafeWebhookUrl::httpClientOptions((string) $this->endpoint, $this->trustedInternalHosts()), [
            'verify' => ! $this->skip_tls_verification,
        ]);
    }

    /**
     * The `docker run` options of the MinIO client helper. `MC_REGION` makes mc sign requests for the storage region,
     * as filesystem() does. Without it, mc signs for us-east-1, and providers that enforce the region reject the
     * requests. `MC_INSECURE` turns off the TLS certificate check, as httpClientOptions() does.
     */
    public function minioClientEnvOptions(): string
    {
        return '-e '.escapeshellarg('MC_REGION='.$this->region)
            .($this->skip_tls_verification ? ' -e MC_INSECURE=true' : '');
    }

    /**
     * The `docker exec` option that makes `mc cp` upload a file of $fileSize bytes in at most 1000 parts.
     * `mc cp` has no part size flag, but it reads the part size from MC_UPLOAD_MULTIPART_SIZE.
     */
    public static function minioClientPartSizeOption(int $fileSize): string
    {
        $mebibyte = 1024 * 1024;
        $partSize = max(
            self::MINIO_CLIENT_MIN_PART_SIZE_BYTES,
            (int) ceil($fileSize / self::MINIO_CLIENT_MAX_UPLOAD_PARTS / $mebibyte) * $mebibyte,
        );

        return '-e MC_UPLOAD_MULTIPART_SIZE='.$partSize;
    }

    /**
     * Path-style URLs (`endpoint/bucket`) are the default. Providers like Alibaba Cloud OSS only accept
     * virtual-hosted-style URLs (`bucket.endpoint`). A storage that is not saved yet has no value, so it uses the default.
     */
    public function usesPathStyleEndpoint(): bool
    {
        return $this->use_path_style_endpoint ?? true;
    }

    /**
     * The URL style for a new storage: virtual-hosted style for providers that reject path-style URLs, else path-style.
     */
    public static function detectPathStyleEndpoint(?string $endpoint): bool
    {
        $host = strtolower((string) parse_url((string) $endpoint, PHP_URL_HOST));

        return ! collect(self::VIRTUAL_HOSTED_STYLE_HOST_SUFFIXES)
            ->contains(fn (string $suffix): bool => $host === $suffix || str_ends_with($host, '.'.$suffix));
    }

    /**
     * Tests the connection with ListObjectsV2. When the provider rejects path-style URLs, it tests again with
     * virtual-hosted-style URLs and keeps that style when it works.
     */
    private function listFilesWithAddressingStyleFallback(): void
    {
        try {
            $this->filesystem()->files();
        } catch (\Throwable $exception) {
            if (! $this->usesPathStyleEndpoint() || ! self::isPathStyleRejection($exception)) {
                throw $exception;
            }

            $this->use_path_style_endpoint = false;
            try {
                $this->filesystem()->files();
            } catch (\Throwable) {
                $this->use_path_style_endpoint = true;

                throw $exception;
            }
        }
    }

    private static function isPathStyleRejection(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (str($current->getMessage())->lower()->contains(self::PATH_STYLE_REJECTION_ERRORS)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `mc alias set` options. `--api S3v4` signs as filesystem() does. Without it, mc probes the signature
     * version on every run, and a network error during the probe makes it try S3v2, which most providers reject.
     * `--path off` uses virtual-hosted-style URLs, as filesystem() does. With path-style URLs, mc keeps its own
     * `auto` lookup, as before.
     */
    public function minioClientAliasOptions(): string
    {
        return ' --api S3v4'.($this->usesPathStyleEndpoint() ? '' : ' --path off');
    }

    /**
     * The MinIO client accepts only `scheme://host[:port]` endpoints. Providers like Supabase Storage need a path
     * (`/storage/v1/s3`), so transfers to these endpoints use curl with a presigned URL instead.
     */
    public function usesCurlTransfer(): bool
    {
        return trim((string) parse_url((string) $this->endpoint, PHP_URL_PATH), '/') !== '';
    }

    /**
     * Prepares the upload of $localPath in $container to $key and returns the command that runs it.
     */
    public function prepareCurlUpload(Server $server, string $container, string $localPath, string $key): string
    {
        $url = $this->filesystem()->temporaryUploadUrl($key, now()->addHours(self::CURL_TRANSFER_URL_TTL_HOURS))['url'];

        // An S3 error is an XML body. Write it to stderr, so it becomes the error message of the backup.
        return $this->prepareCurlTransfer($server, $container, $url, ['upload-file = '.self::curlConfigValue($localPath), 'fail-with-body', 'output = "/dev/stderr"']);
    }

    /**
     * Prepares the download of $key to $localPath in $container and returns the command that runs it.
     */
    public function prepareCurlDownload(Server $server, string $container, string $key, string $localPath): string
    {
        $url = $this->filesystem()->temporaryUrl($key, now()->addHours(self::CURL_TRANSFER_URL_TTL_HOURS));

        return $this->prepareCurlTransfer($server, $container, $url, ['output = '.self::curlConfigValue($localPath), 'fail']);
    }

    /**
     * Writes the curl config of a transfer into $container through the stdin of the SSH process. The config holds
     * the presigned URL, so the URL is not in the arguments of any process, and the S3 keys never reach the server.
     * The `resolve` line pins the endpoint host to its validated IPs, as minioClientResolveOptions() does for mc.
     *
     * @param  array<int, string>  $options
     */
    private function prepareCurlTransfer(Server $server, string $container, string $url, array $options): string
    {
        $entries = SafeWebhookUrl::minioClientResolveOptions((string) $this->endpoint, $this->trustedInternalHosts());
        $config = collect(['url = '.self::curlConfigValue($url), 'silent', 'show-error', ...$options])
            ->when($entries !== [], fn ($lines) => $lines->push('resolve = '.self::curlConfigValue(
                str($entries[0])->beforeLast('=')->toString().':'.collect($entries)
                    ->map(fn (string $entry): string => str($entry)->afterLast('=')->toString())
                    ->map(fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip)
                    ->implode(',')
            )))
            ->when($this->skip_tls_verification, fn ($lines) => $lines->push('insecure'))
            ->implode("\n")."\n";

        $remoteCommand = ($server->isNonRoot() ? 'sudo ' : '').'docker exec -i '.escapeshellarg($container)
            .' sh -c '.escapeshellarg('umask 077 && cat > '.self::CURL_TRANSFER_CONFIG_PATH);
        $timeout = (int) config('constants.ssh.command_timeout');

        SshRetryHandler::retry(
            function () use ($server, $remoteCommand, $config, $timeout) {
                $sshCommand = SshMultiplexingHelper::generateSshStdinCommand($server, $remoteCommand, disableMultiplexing: true, commandTimeout: $timeout);
                $process = runSshProcess($sshCommand, $timeout, $config);

                if ($process->exitCode() !== 0) {
                    excludeCertainErrors($process->errorOutput(), $process->exitCode());
                }
            },
            ['server' => $server->ip, 'function' => 'prepareCurlTransfer'],
        );

        return 'docker exec '.escapeshellarg($container).' curl --config '.self::CURL_TRANSFER_CONFIG_PATH;
    }

    private static function curlConfigValue(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }

    /**
     * The mc `--resolve` option that pins the endpoint host to one of its validated IPs. mc keeps one IP per host,
     * so when the host has more than one IP, a helper container on the upload network connects to each IP, IPv4
     * first, and the first IP that connects is used. A server without IPv4 then uses an IPv6 address of a
     * dual-stack endpoint. When no IP connects, the first IP is used, so mc reports the real connection error.
     */
    public function minioClientResolveOptions(Server $server, string $image, ?string $network = null): string
    {
        $entries = SafeWebhookUrl::minioClientResolveOptions($this->endpoint, $this->trustedInternalHosts());
        if ($entries === []) {
            return '';
        }

        $entry = $entries[0];
        if (count($entries) > 1) {
            $ips = array_map(fn (string $entry): string => str($entry)->afterLast('=')->toString(), $entries);
            $port = (int) str($entries[0])->beforeLast('=')->afterLast(':')->toString();
            $probe = 'for ip in '.implode(' ', array_map('escapeshellarg', $ips)).'; do'
                .' if timeout '.self::RESOLVE_PROBE_TIMEOUT_SECONDS.' bash -c "exec 3<>/dev/tcp/$ip/'.$port.'" 2>/dev/null; then echo "$ip"; exit 0; fi;'
                .' done';
            $reachableIp = trim((string) instant_remote_process(
                ['docker run --rm'.($network === null ? '' : ' --network '.escapeshellarg($network))
                    .' '.escapeshellarg($image).' bash -c '.escapeshellarg($probe)],
                $server,
                throwError: false,
                timeout: count($ips) * self::RESOLVE_PROBE_TIMEOUT_SECONDS + 60,
                disableMultiplexing: true,
            ));
            $reachableIndex = array_search($reachableIp, $ips, true);
            if ($reachableIndex !== false) {
                $entry = $entries[$reachableIndex];
            }
        }

        return ' --resolve '.escapeshellarg($entry);
    }

    /**
     * Runs an upload of a local file with the MinIO client again when it fails, for example after a short network
     * outage. A timeout is not retried, because another full attempt can pass the timeout of the backup job.
     */
    public static function retryMinioClientUpload(callable $upload): mixed
    {
        return retry(
            self::MINIO_CLIENT_UPLOAD_RETRY_DELAYS_MS,
            $upload,
            when: fn (\Throwable $exception): bool => $exception->getCode() !== self::REMOTE_COMMAND_TIMEOUT_EXIT_CODE,
        );
    }

    /**
     * The bundled MinIO container is a trusted internal S3 target, not a user-supplied webhook destination.
     *
     * @return array<int, string>
     */
    public function trustedInternalHosts(): array
    {
        return $this->uuid === 'minio' && parse_url($this->endpoint, PHP_URL_HOST) === 'coolify-minio'
            ? ['coolify-minio']
            : [];
    }

    private function toUserFriendlyConnectionException(\Throwable $exception): \Throwable
    {
        $message = str($exception->getMessage())->lower();

        if ($message->contains(['timed out', 'timeout', 'connection refused', 'could not resolve', 'curl error 28'])) {
            return new \RuntimeException(
                'Could not connect to the S3 endpoint within 15 seconds. Please verify the endpoint, bucket, credentials, region, and network/firewall settings.',
                previous: $exception,
            );
        }

        return $exception;
    }
}
