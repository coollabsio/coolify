<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $organization
 * @property string $api_url
 * @property string $html_url
 * @property int $custom_port
 * @property string $custom_user
 * @property bool $is_system_wide
 * @property bool $is_public
 * @property int|null $app_id
 * @property string|null $app_secret
 * @property int|null $oauth_id
 * @property string|null $group_name
 * @property string|null $public_key
 * @property string|null $webhook_token
 * @property int|null $deploy_key_id
 * @property int|null $private_key_id
 * @property int $team_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Application> $applications
 * @property-read int|null $applications_count
 * @property-read \App\Models\PrivateKey|null $privateKey
 *
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp query()
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereApiUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereAppSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereCustomPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereCustomUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereDeployKeyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereGroupName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereHtmlUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereIsSystemWide($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereOauthId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereOrganization($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp wherePrivateKeyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp wherePublicKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|GitlabApp whereWebhookToken($value)
 *
 * @mixin \Eloquent
 */
class GitlabApp extends BaseModel
{
    protected $hidden = [
        'webhook_token',
        'app_secret',
    ];

    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }
}
