<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $private_key
 * @property bool $is_git_related
 * @property int $team_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\GithubApp> $githubApps
 * @property-read int|null $github_apps_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\GitlabApp> $gitlabApps
 * @property-read int|null $gitlab_apps_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Server> $servers
 * @property-read int|null $servers_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey query()
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey whereIsGitRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey wherePrivateKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PrivateKey whereUuid($value)
 *
 * @mixin \Eloquent
 */
class PrivateKey extends BaseModel
{
    use HasFactory;

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
