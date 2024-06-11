<?php

namespace App\Models;

use phpseclib3\Crypt\PublicKeyLoader;

class PrivateKey extends BaseModel
{
    protected $fillable = [
        'name',
        'description',
        'private_key',
        'is_git_related',
        'team_id',
    ];

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
}
