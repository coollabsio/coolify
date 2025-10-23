<?php

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use phpseclib3\Crypt\PublicKeyLoader;

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
    use HasSafeStringAttribute, WithRateLimiting;

    protected $fillable = [
        'name',
        'description',
        'private_key',
        'is_git_related',
        'team_id',
        'fingerprint',
    ];

    protected $casts = [
        'private_key' => 'encrypted',
    ];

    protected $appends = ['public_key'];

    protected static function booted()
    {
        static::saving(function ($key) {
            $key->private_key = formatPrivateKey($key->private_key);

            if (! self::validatePrivateKey($key->private_key)) {
                throw ValidationException::withMessages([
                    'private_key' => ['The private key is invalid.'],
                ]);
            }

            $key->fingerprint = self::generateFingerprint($key->private_key);
            if (self::fingerprintExists($key->fingerprint, $key->id)) {
                throw ValidationException::withMessages([
                    'private_key' => ['This private key already exists.'],
                ]);
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

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId($teamId)->select($selectArray->all());
    }

    public static function ownedAndOnlySShKeys(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId($teamId)
            ->where('is_git_related', false)
            ->select($selectArray->all());
    }

    public static function validatePrivateKey($privateKey)
    {
        try {
            PublicKeyLoader::load($privateKey);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
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

    public static function generateNewKeyPair($type = 'rsa')
    {
        try {
            $instance = new self;
            $instance->rateLimit(10);
            $name = generate_random_name();
            $description = 'Created by Coolify';
            $keyPair = generateSSHKey($type === 'ed25519' ? 'ed25519' : 'rsa');

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

    public static function validateAndExtractPublicKey($privateKey)
    {
        $isValid = self::validatePrivateKey($privateKey);
        $publicKey = $isValid ? self::extractPublicKeyFromPrivate($privateKey) : '';

        return [
            'isValid' => $isValid,
            'publicKey' => $publicKey,
        ];
    }

    public function storeInFileSystem()
    {
        $filename = "ssh_key@{$this->uuid}";
        $disk = Storage::disk('ssh-keys');

        // Ensure the storage directory exists and is writable
        $this->ensureStorageDirectoryExists();

        // Attempt to store the private key
        $success = $disk->put($filename, $this->private_key);

        if (! $success) {
            throw new \Exception("Failed to write SSH key to filesystem. Check disk space and permissions for: {$this->getKeyLocation()}");
        }

        // Verify the file was actually created and has content
        if (! $disk->exists($filename)) {
            throw new \Exception("SSH key file was not created: {$this->getKeyLocation()}");
        }

        $storedContent = $disk->get($filename);
        if (empty($storedContent) || $storedContent !== $this->private_key) {
            $disk->delete($filename); // Clean up the bad file
            throw new \Exception("SSH key file content verification failed: {$this->getKeyLocation()}");
        }

        return $this->getKeyLocation();
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
            throw new \Exception('SSH keys storage directory is not writable');
        }

        // Clean up test file
        $disk->delete($testFilename);
    }

    public function getKeyLocation()
    {
        return "/var/www/html/storage/app/ssh/keys/ssh_key@{$this->uuid}";
    }

    public function updatePrivateKey(array $data)
    {
        return DB::transaction(function () use ($data) {
            $this->update($data);

            try {
                $this->storeInFileSystem();
            } catch (\Exception $e) {
                throw new \Exception('Failed to update SSH key: '.$e->getMessage());
            }

            return $this;
        });
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

    public function isInUse()
    {
        return $this->servers()->exists()
            || $this->applications()->exists()
            || $this->githubApps()->exists()
            || $this->gitlabApps()->exists();
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

    public static function fingerprintExists($fingerprint, $excludeId = null)
    {
        $query = self::query()
            ->where('fingerprint', $fingerprint)
            ->where('id', '!=', $excludeId);

        if (currentTeam()) {
            $query->where('team_id', currentTeam()->id);
        }

        return $query->exists();
    }

    public static function cleanupUnusedKeys()
    {
        self::ownedByCurrentTeam()->each(function ($privateKey) {
            $privateKey->safeDelete();
        });
    }
}
