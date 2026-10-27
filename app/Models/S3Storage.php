<?php

namespace App\Models;

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
                        send_user_an_email($mail, $user->email);
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
            'http' => array_merge(SafeWebhookUrl::httpClientOptions($this['endpoint'], $this->trustedInternalHosts()), [
                'connect_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]),
        ]);
    }

    /**
     * The `docker run` option that makes the MinIO client sign requests for the storage region, as filesystem() does.
     * Without it, mc signs for us-east-1, and providers that enforce the region reject the requests.
     */
    public function minioClientRegionOption(): string
    {
        return '-e '.escapeshellarg('MC_REGION='.$this->region);
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
     * The `mc alias set` option that makes the MinIO client use virtual-hosted-style URLs, as filesystem() does.
     * With path-style URLs, mc keeps its own `auto` lookup, as before.
     */
    public function minioClientPathOption(): string
    {
        return $this->usesPathStyleEndpoint() ? '' : ' --path off';
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
