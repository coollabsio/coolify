<?php

namespace App\Models;

use App\Enums\BackupStorageType;
use App\Helpers\SshMultiplexingHelper;
use App\Helpers\SshRetryHandler;
use App\Rules\SafeWebhookUrl;
use App\Rules\ValidS3BucketName;
use App\Support\FtpsFilesystemAdapter;
use App\Support\PinnedSftpConnectionProvider;
use App\Support\SftpFilesystemAdapter;
use App\Support\WebdavFilesystemAdapter;
use App\Traits\Auditable;
use App\Traits\HasSafeStringAttribute;
use GuzzleHttp\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;

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
     * The name of the rclone remote in rcloneConfig().
     */
    public const RCLONE_REMOTE = 'storage';

    public const SFTP_HOST_KEY_MISSING_MESSAGE = 'The SFTP host key of this storage is not saved yet. Validate the connection in the storage settings to save it.';

    /**
     * The key of the rclone password obscuring (fs/config/obscure in rclone). It only hides the password from a casual
     * look at the config file; it is not encryption.
     */
    private const RCLONE_OBSCURE_KEY = '9c935b48730a554d6bfd7c63c886a92bd390198eb8128afbf4de162b8b95f638';

    /**
     * How long the helper container waits for a TCP connection to one IP of the endpoint.
     */
    private const RESOLVE_PROBE_TIMEOUT_SECONDS = 5;

    protected $fillable = [
        'team_id',
        'type',
        'name',
        'description',
        'region',
        'key',
        'secret',
        'bucket',
        'endpoint',
        'use_path_style_endpoint',
        'private_key_id',
        'skip_tls_verification',
        'is_usable',
        'unusable_email_sent',
    ];

    protected $hidden = [
        'key',
        'secret',
    ];

    protected $attributes = [
        'type' => 's3',
    ];

    protected $casts = [
        'type' => BackupStorageType::class,
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
            // An SFTP storage that logs in with a private key has no password. The column is not nullable.
            $storage->secret = trim((string) $storage->secret);
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

    /**
     * Storages that serve presigned URLs: file downloads and avatars need them, so they only use S3 storages.
     */
    public function scopeS3Only(Builder $query): Builder
    {
        return $query->where('type', BackupStorageType::S3->value);
    }

    public function isS3(): bool
    {
        return $this->storageType() === BackupStorageType::S3;
    }

    /**
     * The type of the storage. A model without the `type` attribute (a partial select, or raw attributes) is an S3
     * storage, as every storage was before other types existed.
     */
    public function storageType(): BackupStorageType
    {
        return $this->type ?? BackupStorageType::S3;
    }

    /**
     * Validation rules for the connection fields of a storage of this type. `$partial` makes every field optional, for
     * updates that only send some fields.
     *
     * `$savedEndpoint` is the endpoint that is saved now: it may keep a username or password from before this check.
     *
     * @param  array<int, string>  $trustedInternalHosts
     * @return array<string, array<int, mixed>>
     */
    public static function connectionRules(BackupStorageType $type, bool $partial = false, array $trustedInternalHosts = [], ?int $teamId = null, ?string $savedEndpoint = null): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $endpoint = [
            $required,
            'string',
            'max:255',
            // Team members and read-only API tokens see the endpoint, so it must not hold credentials.
            function (string $attribute, mixed $value, \Closure $fail) use ($savedEndpoint): void {
                $parts = is_string($value) ? (parse_url(trim($value)) ?: []) : [];
                if ((isset($parts['user']) || isset($parts['pass'])) && trim((string) $value) !== trim((string) $savedEndpoint)) {
                    $fail('The :attribute must not contain a username or password. Enter the credentials in their own fields.');
                }
                if (self::hasControlCharacters(rawurldecode((string) ($parts['path'] ?? '')))) {
                    $fail('The :attribute path must not contain control characters.');
                }
            },
            new SafeWebhookUrl(trustedInternalHosts: $trustedInternalHosts, schemes: $type->endpointSchemes()),
        ];

        if ($type === BackupStorageType::S3) {
            return [
                'endpoint' => $endpoint,
                'bucket' => [$required, new ValidS3BucketName],
                'region' => [$required, 'string', 'max:255'],
                'key' => [$required, 'string', 'max:255'],
                'secret' => [$required, 'string', 'max:255'],
            ];
        }

        $rules = [
            'endpoint' => $endpoint,
            // rclone reads the user from a config file line, so it must not contain line breaks or quotes.
            'key' => [$required, 'string', 'max:255', 'regex:/^[^\x00-\x1f\x7f"]+$/'],
            'secret' => [$required, 'string', 'max:255'],
        ];

        if ($type === BackupStorageType::Sftp) {
            // SFTP logs in with a private key of the storage team or with a password.
            $rules['private_key_id'] = ['nullable', 'integer', Rule::exists('private_keys', 'id')->where('team_id', $teamId ?? -1)->where(fn ($query) => $query->where('is_git_related', false))];
            $rules['secret'] = [$partial ? 'sometimes' : 'required_without:private_key_id', 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * The endpoint URL of an SFTP or FTPS storage, from the form fields.
     */
    public static function serverEndpoint(BackupStorageType $type, string $host, ?string $port = '', string $path = ''): string
    {
        $host = trim($host);
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }
        $port = trim((string) $port);
        $path = implode('/', array_map('rawurlencode', array_filter(explode('/', trim($path)), fn (string $segment): bool => $segment !== '')));

        return $type->endpointSchemes()[0].'://'.$host.($port !== '' ? ':'.$port : '').($path !== '' ? '/'.$path : '');
    }

    /**
     * The form fields of an SFTP or FTPS endpoint URL.
     *
     * @return array{host: string, port: string, path: string}
     */
    public static function splitServerEndpoint(?string $endpoint): array
    {
        $parts = parse_url((string) $endpoint) ?: [];

        return [
            'host' => trim((string) ($parts['host'] ?? ''), '[]'),
            'port' => isset($parts['port']) ? (string) $parts['port'] : '',
            'path' => trim(rawurldecode((string) ($parts['path'] ?? '')), '/'),
        ];
    }

    /**
     * The directory on an SFTP or FTPS server that holds the backups: the path of the endpoint URL. Empty means the
     * login directory of the user.
     */
    public function basePath(): string
    {
        $path = rtrim(rawurldecode((string) parse_url((string) $this->endpoint, PHP_URL_PATH)), '/');
        if (self::hasControlCharacters($path)) {
            throw new \RuntimeException('The storage path must not contain control characters.');
        }

        return $path;
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1f\x7f]/', $value) === 1;
    }

    public function endpointPort(): int
    {
        return (int) (parse_url((string) $this->endpoint, PHP_URL_PORT) ?: $this->storageType()->defaultPort());
    }

    /**
     * The path of a backup file in the storage, as rclone reads it. The file keeps its full local path, as the S3 object
     * key does, below the base path (SFTP, FTPS) or the base URL (WebDAV).
     */
    public function rcloneRemotePath(string $file): string
    {
        $file = ltrim($file, '/');
        $base = $this->storageType() === BackupStorageType::Webdav ? '' : $this->basePath();

        return self::RCLONE_REMOTE.':'.($base === '' ? $file : $base.'/'.$file);
    }

    /**
     * The rclone config of this storage. The server gets it through stdin, so the password is never on a command line.
     */
    public function rcloneConfig(): string
    {
        $host = trim((string) parse_url((string) $this->endpoint, PHP_URL_HOST), '[]');
        $options = match ($this->storageType()) {
            // host_keys makes rclone refuse another host key, and offer only the algorithm of the saved key.
            BackupStorageType::Sftp => ['type' => 'sftp', 'host' => $host, 'port' => $this->endpointPort(), 'shell_type' => 'none', 'host_keys' => $this->requireSftpHostKey()],
            BackupStorageType::Ftps => ['type' => 'ftp', 'host' => $host, 'port' => $this->endpointPort(), 'explicit_tls' => 'true'],
            BackupStorageType::Webdav => ['type' => 'webdav', 'url' => $this->endpoint, 'vendor' => 'other'],
            BackupStorageType::S3 => throw new \LogicException('S3 storages use the MinIO client.'),
        };
        $options['user'] = $this->key;
        $privateKey = $this->sftpPrivateKey();
        if ($privateKey) {
            // rclone reads key_pem from one line, with \n for each line break.
            $options['key_pem'] = str_replace("\n", '\n', trim(str_replace("\r\n", "\n", (string) $privateKey->private_key)));
        } else {
            $options['pass'] = self::rcloneObscure((string) $this->secret);
        }

        $lines = ['['.self::RCLONE_REMOTE.']'];
        foreach ($options as $name => $value) {
            if (preg_match('/[\x00-\x1f\x7f]/', (string) $value)) {
                throw new \RuntimeException("The storage {$name} must not contain control characters.");
            }
            $lines[] = "{$name} = {$value}";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Obscures a password as `rclone obscure` does: AES-CTR with the fixed rclone key and a random IV, in URL-safe
     * base64 without padding.
     */
    public static function rcloneObscure(string $value): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($value, 'aes-256-ctr', hex2bin(self::RCLONE_OBSCURE_KEY), OPENSSL_RAW_DATA, $iv);

        return rtrim(strtr(base64_encode($iv.$ciphertext), '+/', '-_'), '=');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The private key that an SFTP storage logs in with. Null: it logs in with the password.
     */
    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    /**
     * The private key of an SFTP storage, only when it belongs to the team of the storage.
     */
    public function sftpPrivateKey(): ?PrivateKey
    {
        if ($this->storageType() !== BackupStorageType::Sftp || $this->private_key_id === null) {
            return null;
        }

        $privateKey = $this->privateKey;
        if (! $privateKey || (int) $privateKey->team_id !== (int) $this->team_id || $privateKey->is_git_related) {
            throw new \RuntimeException('The private key of this storage does not exist, belongs to another team, or is a Git deploy key.');
        }

        return $privateKey;
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
     * Trim whitespace from endpoint to prevent malformed URLs. Another host or port is another server, so the saved SFTP
     * host key no longer applies.
     */
    protected function endpoint(): Attribute
    {
        return Attribute::make(
            set: function (?string $value, array $attributes): array {
                $value = $value ? trim($value) : null;
                $previous = $attributes['endpoint'] ?? null;
                $server = fn (?string $endpoint): array => [strtolower(trim((string) parse_url((string) $endpoint, PHP_URL_HOST), '[]')), parse_url((string) $endpoint, PHP_URL_PORT)];

                if ($previous === null || $server($previous) === $server($value)) {
                    return ['endpoint' => $value];
                }

                // Without the host key, the storage needs a new connection test before Coolify uses it.
                return ['endpoint' => $value, 'sftp_host_key' => null, ...(($attributes['sftp_host_key'] ?? null) !== null ? ['is_usable' => false] : [])];
            },
        );
    }

    /**
     * The SHA256 fingerprint of the saved SFTP host key, as `ssh-keygen -l` prints it.
     */
    public function sftpHostKeyFingerprint(): ?string
    {
        return $this->sftp_host_key ? PinnedSftpConnectionProvider::fingerprint($this->sftp_host_key) : null;
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

    /**
     * @param  bool  $acceptNewHostKey  Only when an admin validates the storage: an SFTP storage without a saved host
     *                                  key then saves the presented key. Background tests refuse such a storage, so a
     *                                  reset or changed key is never trusted without the admin.
     */
    public function testConnection(bool $shouldSave = false, bool $acceptNewHostKey = false)
    {
        try {
            if ($this->isS3()) {
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
            } else {
                $validator = Validator::make(
                    $this->only(['endpoint', 'key', 'secret', 'private_key_id']),
                    self::connectionRules($this->storageType(), trustedInternalHosts: $this->trustedInternalHosts(), teamId: $this->team_id, savedEndpoint: $this->endpoint),
                );
                if ($validator->fails()) {
                    throw new \RuntimeException('Storage settings are not allowed: '.$validator->errors()->first());
                }

                if ($this->storageType() === BackupStorageType::Sftp && ! $acceptNewHostKey) {
                    $this->requireSftpHostKey();
                }

                $disk = $this->storageType() === BackupStorageType::Sftp ? $this->sftpFilesystem() : $this->filesystem();
                $disk->files();
                $adapter = $disk->getAdapter();
                if ($adapter instanceof SftpFilesystemAdapter && $this->sftp_host_key === null) {
                    $this->pinSftpHostKey((string) $adapter->presentedHostKey(), $shouldSave);
                }
            }

            $this->unusable_email_sent = false;
            $this->is_usable = true;
        } catch (\Throwable $e) {
            $exception = $this->toUserFriendlyConnectionException($e);
            $this->is_usable = false;
            if ($this->unusable_email_sent === false && is_transactional_emails_enabled()) {
                try {
                    $mail = new MailMessage;
                    $mail->subject('Coolify: Backup Storage Connection Error');
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

    /**
     * The files of the storage, as Coolify reads them (connection tests, restores, downloads, retention). SFTP and FTPS
     * connect to the validated IP of the host, and WebDAV pins it, so a DNS change cannot send them to another address.
     * SFTP also checks the saved host key, and refuses to connect before testConnection() saved it.
     */
    public function filesystem(): FilesystemAdapter
    {
        if ($this->storageType() === BackupStorageType::Sftp) {
            $this->requireSftpHostKey();
        }

        return match ($this->storageType()) {
            BackupStorageType::S3 => $this->s3Filesystem(),
            BackupStorageType::Sftp => $this->sftpFilesystem(),
            BackupStorageType::Ftps => $this->adapterFilesystem(new FtpsFilesystemAdapter(
                host: trim((string) parse_url((string) $this->endpoint, PHP_URL_HOST), '[]'),
                port: $this->endpointPort(),
                username: (string) $this->key,
                password: (string) $this->secret,
                root: $this->basePath(),
                pinnedIp: $this->pinnedHost(),
            )),
            BackupStorageType::Webdav => $this->webdavFilesystem(),
        };
    }

    /**
     * Without a saved host key, it accepts any key. Only testConnection() uses it so, to save the presented key.
     */
    private function sftpFilesystem(): FilesystemAdapter
    {
        return $this->adapterFilesystem(new SftpFilesystemAdapter(PinnedSftpConnectionProvider::create(
            host: trim((string) parse_url((string) $this->endpoint, PHP_URL_HOST), '[]'),
            address: $this->pinnedHost(),
            port: $this->endpointPort(),
            username: (string) $this->key,
            password: $this->sftpPrivateKey() ? null : (string) $this->secret,
            privateKey: $this->sftpPrivateKey()?->private_key,
            pinnedHostKey: $this->sftp_host_key,
            timeout: self::CONNECTION_TIMEOUT_SECONDS,
        ), $this->basePath()));
    }

    private function requireSftpHostKey(): string
    {
        if (blank($this->sftp_host_key)) {
            throw new \RuntimeException(self::SFTP_HOST_KEY_MISSING_MESSAGE);
        }

        return $this->sftp_host_key;
    }

    /**
     * Saves the host key of the first connection only while the saved storage still has the tested endpoint and no
     * key, so a test that started before an endpoint change cannot save the key of the old server.
     */
    private function pinSftpHostKey(string $hostKey, bool $shouldSave): void
    {
        if ($hostKey === '') {
            throw new \RuntimeException('The SFTP server did not present a host key.');
        }
        if ($shouldSave && $this->exists) {
            $saved = static::query()->whereKey($this->getKey())
                ->where('endpoint', $this->getAttributes()['endpoint'] ?? null)
                ->whereNull('sftp_host_key')
                ->update(['sftp_host_key' => $hostKey]);
            if ($saved === 0) {
                throw new \RuntimeException('The storage settings changed during the connection test. Validate the connection again.');
            }
            $this->sftp_host_key = $hostKey;
            $this->syncOriginalAttribute('sftp_host_key');

            return;
        }

        $this->sftp_host_key = $hostKey;
    }

    private function pinnedHost(): string
    {
        return SafeWebhookUrl::pinnedAddress((string) $this->endpoint, $this->storageType()->endpointSchemes(), $this->trustedInternalHosts());
    }

    private function adapterFilesystem(FlysystemAdapter $adapter): FilesystemAdapter
    {
        return new FilesystemAdapter(new Filesystem($adapter), $adapter);
    }

    private function webdavFilesystem(): FilesystemAdapter
    {
        $baseUrl = rtrim((string) $this->endpoint, '/').'/';
        $client = new Client(array_merge(SafeWebhookUrl::httpClientOptions($baseUrl, $this->trustedInternalHosts()), [
            'base_uri' => $baseUrl,
            'auth' => [$this->key, $this->secret],
            'http_errors' => false,
            'connect_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
        ]));

        return $this->adapterFilesystem(new WebdavFilesystemAdapter($client, $baseUrl));
    }

    private function s3Filesystem(): FilesystemAdapter
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
        return $this->isS3() && trim((string) parse_url((string) $this->endpoint, PHP_URL_PATH), '/') !== '';
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
                $this->isS3()
                    ? 'Could not connect to the S3 endpoint within 15 seconds. Please verify the endpoint, bucket, credentials, region, and network/firewall settings.'
                    : 'Could not connect to the '.$this->storageType()->label().' server within 15 seconds. Please verify the host, port, credentials, and network/firewall settings.',
                previous: $exception,
            );
        }

        return $exception;
    }
}
