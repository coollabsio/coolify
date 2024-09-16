<?php

namespace App\Models;

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
    protected $fillable = [
        'name',
        'description',
        'private_key',
        'fingerprint',
        'is_git_related',
        'team_id',
    ];

    protected static function booted()
    {
        static::saving(function ($key) {
            $privateKey = data_get($key, 'private_key');
            if (substr($privateKey, -1) !== "\n") {
                $key->private_key = $privateKey . "\n";
            }
            $key->fingerprint = $key->generateFingerprint();
        });
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return PrivateKey::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }

    public function publicKey()
    {
        try {
            return PublicKeyLoader::load($this->private_key)->getPublicKey()->toString('OpenSSH', ['comment' => '']);
        } catch (\Throwable $e) {
            return 'Error loading private key';
        }
    }

    public function isEmpty()
    {
        if ($this->servers()->count() === 0 && $this->applications()->count() === 0 && $this->githubApps()->count() === 0 && $this->gitlabApps()->count() === 0) {
            return true;
        }

        return false;
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

    public function generateFingerprint()
    {
        try {
            $key = PublicKeyLoader::load($this->private_key);
            return $key->getPublicKey()->getFingerprint('sha256');
        } catch (\Throwable $e) {
            return 'invalid_' . md5($this->private_key); // TODO: DO NOT ALLOW SAVING IF INVALID SSH KEYS SAY SSH KEY IS INVALID
        }
    }
}
