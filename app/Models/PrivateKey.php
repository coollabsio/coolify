<?php

namespace App\Models;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
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
        'is_git_related' => ['type' => 'boolean'],
        'team_id' => ['type' => 'integer'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ],
)]
class PrivateKey extends BaseModel
{
    use WithRateLimiting;

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
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
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
        $privateKey = new self($data);
        $privateKey->save();
        $privateKey->storeInFileSystem();

        return $privateKey;
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
        Storage::disk('ssh-keys')->put($filename, $this->private_key);

        return "/var/www/html/storage/app/ssh/keys/{$filename}";
    }

    public static function deleteFromStorage(self $privateKey)
    {
        $filename = "ssh_key@{$privateKey->uuid}";
        Storage::disk('ssh-keys')->delete($filename);
    }

    public function getKeyLocation()
    {
        return "/var/www/html/storage/app/ssh/keys/ssh_key@{$this->uuid}";
    }

    public function updatePrivateKey(array $data)
    {
        $this->update($data);
        $this->storeInFileSystem();

        return $this;
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
