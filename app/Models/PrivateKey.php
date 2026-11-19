<?php

namespace App\Models;

use App\Helpers\SshMultiplexingHelper;
use App\Traits\Auditable;
use App\Traits\HasSafeStringAttribute;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use phpseclib3\Crypt\DSA;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

#[OA\Schema(
    description: 'Private Key model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'name' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'private_key' => ['type' => 'string', 'format' => 'private-key'],
        'public_key' => ['type' => 'string', 'description' => 'The public key of the private key.'],
        'fingerprint' => ['type' => 'string', 'description' => 'The fingerprint of the private key.'],
        'is_git_related' => ['type' => 'boolean'],
        'team_id' => ['type' => 'integer'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ],
)]
class PrivateKey extends BaseModel
{
    use Auditable, HasFactory, HasSafeStringAttribute, WithRateLimiting;

    protected $fillable = [
        'name',
        'description',
        'private_key',
        'is_git_related',
        'team_id',
        'fingerprint',
    ];

    protected $hidden = [
        'private_key',
    ];

    protected $casts = [
        'private_key' => 'encrypted',
        'is_git_related' => 'boolean',
    ];

    /**
     * Accept any key that can be loaded. A server transfer sets it, because the key already works on the source.
     */
    public bool $allowLegacyKey = false;

    protected $appends = ['public_key'];

    protected static function booted()
    {
        static::saving(function ($key) {
            $storedPrivateKey = $key->exists ? $key->getOriginal('private_key') : null;
            $key->private_key = formatPrivateKey($key->private_key);

            // Check only new or changed keys, so older keys can still be renamed.
            $normalize = fn (string $privateKey): string => str_replace("\r\n", "\n", formatPrivateKey($privateKey));
            $isChanged = $storedPrivateKey === null || $normalize($key->private_key) !== $normalize($storedPrivateKey);
            $error = $isChanged ? self::validationError($key->private_key, ! $key->allowLegacyKey) : null;
            if ($error) {
                throw ValidationException::withMessages([
                    'private_key' => [$error],
                ]);
            }

            $key->fingerprint = self::generateFingerprint($key->private_key);
            if (self::fingerprintExists($key->fingerprint, $key->id, $key->team_id)) {
                throw ValidationException::withMessages([
                    'private_key' => ['This private key already exists.'],
                ]);
            }
        });

        static::saved(function ($key) {
            if ($key->wasChanged('private_key')) {
                try {
                    $key->storeInFileSystem();
                    refresh_server_connection($key);
                } catch (\Exception $e) {
                    Log::error('Failed to resync SSH key after update', [
                        'key_uuid' => $key->uuid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        static::deleted(function ($key) {
            self::deleteFromStorage($key);
        });
    }

    public function getPublicKeyAttribute()
    {
        return self::extractPublicKeyFromPrivate($this->private_key) ?? 'Error loading private key';
    }

    public function getPublicKey()
    {
        return self::extractPublicKeyFromPrivate($this->private_key) ?? 'Error loading private key';
    }

    /**
     * Get query builder for private keys owned by current team.
     * If you need all private keys without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId($teamId)->select($selectArray->all());
    }

    /**
     * Get all private keys owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return PrivateKey::ownedByCurrentTeam()->get();
        });
    }

    public static function ownedAndOnlySShKeys(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId($teamId)
            ->where('is_git_related', false)
            ->select($selectArray->all());
    }

    /**
     * Returns why a private key cannot be used, or null when it is valid.
     * Without $strict, only checks that the key can be loaded.
     */
    public static function validationError(?string $privateKey, bool $strict = true): ?string
    {
        try {
            $key = PublicKeyLoader::loadPrivateKey((string) $privateKey);
        } catch (\Throwable $e) {
            return 'The private key is invalid. Paste an unencrypted private key, not a public key or a certificate.';
        }

        if (! $strict) {
            return null;
        }

        if (! str_starts_with(trim((string) $privateKey), '-----BEGIN ')) {
            return 'PuTTY and other key formats are not supported. Paste the key in OpenSSH or PEM format. It starts with "-----BEGIN".';
        }

        if ($key instanceof DSA) {
            return 'DSA keys are not supported. Use an Ed25519 or RSA key.';
        }

        if ($key instanceof RSA && $key->getLength() < 2048) {
            return 'RSA keys must have at least 2048 bits.';
        }

        try {
            $key->getPublicKey()->toString('OpenSSH');
        } catch (\Throwable $e) {
            return 'This key type is not supported. Use an Ed25519, ECDSA (nistp256, nistp384, or nistp521), or RSA key.';
        }

        return null;
    }

    public static function createAndStore(array $data)
    {
        return DB::transaction(function () use ($data) {
            $privateKey = new self($data);
            $privateKey->save();

            try {
                $privateKey->storeInFileSystem();
            } catch (\Exception $e) {
                throw new \Exception('Failed to store SSH key: '.$e->getMessage());
            }

            return $privateKey;
        });
    }

    /**
     * @param  int|null  $rsaKeyLength  The RSA key length in bits; null keeps the default of generateSSHKey().
     */
    public static function generateNewKeyPair($type = 'ed25519', ?int $rsaKeyLength = null)
    {
        try {
            $instance = new self;
            $instance->rateLimit(10);
            $name = generate_random_name();
            $description = 'Created by Coolify';
            if ($type === 'rsa' && $rsaKeyLength !== null) {
                $key = RSA::createKey($rsaKeyLength);
                $keyPair = [
                    'private' => $key->toString('PKCS1'),
                    'public' => $key->getPublicKey()->toString('OpenSSH', ['comment' => 'coolify-generated-ssh-key']),
                ];
            } else {
                $keyPair = generateSSHKey($type === 'rsa' ? 'rsa' : 'ed25519');
            }

            return [
                'name' => $name,
                'description' => $description,
                'private_key' => $keyPair['private'],
                'public_key' => $keyPair['public'],
            ];
        } catch (\Throwable $e) {
            throw new \Exception("Failed to generate new {$type} key: ".$e->getMessage());
        }
    }

    public static function extractPublicKeyFromPrivate($privateKey)
    {
        try {
            $key = PublicKeyLoader::load($privateKey);

            return $key->getPublicKey()->toString('OpenSSH', ['comment' => '']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The key type and key data, without a comment. Lines in authorized_keys are found by this value.
     */
    public function publicKeyBody(): string
    {
        $publicKey = self::extractPublicKeyFromPrivate($this->private_key);
        if (is_null($publicKey)) {
            throw new \RuntimeException('The private key is invalid.');
        }

        return implode(' ', array_slice(preg_split('/\s+/', trim($publicKey)), 0, 2));
    }

    /**
     * The authorized_keys line that Coolify adds. The comment names the instance and the key,
     * and does not contain the word "coolify", which old install scripts remove.
     */
    public function authorizedKeysLine(): string
    {
        $instance = substr(hash('sha256', (string) (config('app.id') ?: config('app.url'))), 0, 12);

        return "{$this->publicKeyBody()} cpk:{$instance}:{$this->uuid}";
    }

    public function storeInFileSystem()
    {
        $filename = "ssh_key@{$this->uuid}";
        $disk = Storage::disk('ssh-keys');
        $keyLocation = $this->getKeyLocation();
        $lockFile = $keyLocation.'.lock';

        // Ensure the storage directory exists and is writable
        $this->ensureStorageDirectoryExists();

        // Use file locking to prevent concurrent writes from corrupting the key
        $lockHandle = fopen($lockFile, 'c');
        if ($lockHandle === false) {
            throw new \Exception("Failed to open lock file for SSH key: {$lockFile}");
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                throw new \Exception("Failed to acquire lock for SSH key: {$keyLocation}");
            }

            // Attempt to store the private key
            $success = $disk->put($filename, $this->private_key);

            if (! $success) {
                throw new \Exception("Failed to write SSH key to filesystem. Check disk space and permissions for: {$keyLocation}");
            }

            // Verify the file was actually created and has content
            if (! $disk->exists($filename)) {
                throw new \Exception("SSH key file was not created: {$keyLocation}");
            }

            $storedContent = $disk->get($filename);
            if (empty($storedContent) || $storedContent !== $this->private_key) {
                $disk->delete($filename); // Clean up the bad file
                throw new \Exception("SSH key file content verification failed: {$keyLocation}");
            }

            // Ensure correct permissions for SSH (0600 required)
            if (file_exists($keyLocation) && ! chmod($keyLocation, 0600)) {
                Log::warning('Failed to set SSH key file permissions to 0600', [
                    'key_uuid' => $this->uuid,
                    'path' => $keyLocation,
                ]);
            }
            SshMultiplexingHelper::giveToSshDirectoryOwner($keyLocation, $lockFile);

            return $keyLocation;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public static function deleteFromStorage(self $privateKey)
    {
        $filename = "ssh_key@{$privateKey->uuid}";
        $disk = Storage::disk('ssh-keys');

        if ($disk->exists($filename)) {
            $disk->delete($filename);
        }
    }

    protected function ensureStorageDirectoryExists()
    {
        $disk = Storage::disk('ssh-keys');
        $directoryPath = '';

        if (! $disk->exists($directoryPath)) {
            $success = $disk->makeDirectory($directoryPath);
            if (! $success) {
                throw new \Exception('Failed to create SSH keys storage directory');
            }
        }

        // Check if directory is writable by attempting a test file
        $testFilename = '.test_write_'.uniqid();
        $testSuccess = $disk->put($testFilename, 'test');

        if (! $testSuccess) {
            throw new \Exception('SSH keys storage directory is not writable. Run on the host: sudo chown -R 9999 /data/coolify/ssh && sudo chmod -R 700 /data/coolify/ssh && docker restart coolify');
        }

        // Clean up test file
        $disk->delete($testFilename);
    }

    public function getKeyLocation()
    {
        return Storage::disk('ssh-keys')->path("ssh_key@{$this->uuid}");
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function githubApps()
    {
        return $this->hasMany(GithubApp::class);
    }

    public function gitlabApps()
    {
        return $this->hasMany(GitlabApp::class);
    }

    public function giteaApps()
    {
        return $this->hasMany(GiteaApp::class);
    }

    public function s3Storages(): HasMany
    {
        return $this->hasMany(S3Storage::class);
    }

    public function isInUse()
    {
        return $this->servers()->exists()
            || $this->s3Storages()->exists()
            || $this->applications()->exists()
            || $this->githubApps()->exists()
            || $this->gitlabApps()->exists()
            || $this->giteaApps()->exists();
    }

    public function safeDelete()
    {
        if (! $this->isInUse()) {
            $this->delete();

            return true;
        }

        return false;
    }

    public static function generateFingerprint($privateKey)
    {
        try {
            $key = PublicKeyLoader::load($privateKey);

            return $key->getPublicKey()->getFingerprint('sha256');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function generateMd5Fingerprint($privateKey)
    {
        try {
            $key = PublicKeyLoader::load($privateKey);

            return $key->getPublicKey()->getFingerprint('md5');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether another key with this fingerprint exists in the given team.
     *
     * The check is always limited to $teamId, never to the signed in user's
     * team or to all teams. Without a team id nothing can match, because every
     * key belongs to a team.
     */
    public static function fingerprintExists(?string $fingerprint, ?int $excludeId = null, ?int $teamId = null): bool
    {
        if (is_null($fingerprint) || is_null($teamId)) {
            return false;
        }

        return self::query()
            ->where('team_id', $teamId)
            ->where('fingerprint', $fingerprint)
            ->when(! is_null($excludeId), fn ($query) => $query->whereKeyNot($excludeId))
            ->exists();
    }

    public static function cleanupUnusedKeys()
    {
        self::ownedByCurrentTeam()->each(function ($privateKey) {
            $privateKey->safeDelete();
        });
    }
}
