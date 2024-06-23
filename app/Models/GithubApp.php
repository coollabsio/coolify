<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $organization
 * @property string $api_url
 * @property string $html_url
 * @property string $custom_user
 * @property int $custom_port
 * @property int|null $app_id
 * @property int|null $installation_id
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $webhook_secret
 * @property bool $is_system_wide
 * @property bool $is_public
 * @property int|null $private_key_id
 * @property int $team_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $contents
 * @property string|null $metadata
 * @property string|null $pull_requests
 * @property string|null $administration
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \App\Models\PrivateKey|null $privateKey
 * @property-read mixed $type
 *
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp query()
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereAdministration($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereApiUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereClientSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereContents($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereCustomPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereCustomUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereHtmlUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereInstallationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereIsSystemWide($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereOrganization($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp wherePrivateKeyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp wherePullRequests($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GithubApp whereWebhookSecret($value)
 *
 * @mixin \Eloquent
 */
class GithubApp extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected $appends = ['type'];

    protected $casts = [
        'is_public' => 'boolean',
        'type' => 'string',
    ];

    protected $hidden = [
        'client_secret',
        'webhook_secret',
    ];

    public static function public()
    {
        return GithubApp::whereTeamId(currentTeam()->id)->whereisPublic(true)->whereNotNull('app_id')->get();
    }

    public static function private()
    {
        return GithubApp::whereTeamId(currentTeam()->id)->whereisPublic(false)->whereNotNull('app_id')->get();
    }

    protected static function booted(): void
    {
        static::deleting(function (GithubApp $github_app) {
            $applications_count = Application::where('source_id', $github_app->id)->count();
            if ($applications_count > 0) {
                throw new \Exception('You cannot delete this GitHub App because it is in use by '.$applications_count.' application(s). Delete them first.');
            }
            $github_app->privateKey()->delete();
        });
    }

    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function type(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->getMorphClass() === 'App\Models\GithubApp') {
                    return 'github';
                }
            },
        );
    }
}
