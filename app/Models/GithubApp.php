<?php

namespace App\Models;

use App\Enums\GithubRunnerStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;

class GithubApp extends BaseModel
{
    use Auditable;

    public function delete(): ?bool
    {
        return DB::transaction(fn () => parent::delete());
    }

    protected $fillable = [
        'team_id',
        'private_key_id',
        'name',
        'organization',
        'api_url',
        'html_url',
        'custom_user',
        'custom_port',
        'app_id',
        'installation_id',
        'client_id',
        'client_secret',
        'webhook_secret',
        'is_system_wide',
        'is_public',
        'contents',
        'metadata',
        'pull_requests',
        'administration',
        'organization_self_hosted_runners',
        'actions',
        'webhook_events',
        'runner_group_id',
    ];

    protected $appends = ['type'];

    protected $casts = [
        'is_public' => 'boolean',
        'is_system_wide' => 'boolean',
        'type' => 'string',
        'webhook_events' => 'array',
        'runner_group_id' => 'integer',
        'client_secret' => 'encrypted',
        'webhook_secret' => 'encrypted',
    ];

    protected $hidden = [
        'client_secret',
        'webhook_secret',
    ];

    /**
     * Installation token kept in memory, so one job does not request a new token for each GitHub call.
     *
     * @var array{credentials: string, token: string, expires_at: int}|null
     */
    private ?array $installationTokenInMemory = null;

    public function installationToken(): string
    {
        $credentials = implode('|', [$this->api_url, $this->app_id, $this->installation_id, $this->private_key_id]);
        $cached = $this->installationTokenInMemory;

        if ($cached && $cached['credentials'] === $credentials && $cached['expires_at'] > now()->getTimestamp()) {
            return $cached['token'];
        }

        $token = generateGithubToken($this, 'installation');

        // GitHub installation tokens expire after one hour; renew them well before that.
        $this->installationTokenInMemory = [
            'credentials' => $credentials,
            'token' => $token,
            'expires_at' => now()->addMinutes(50)->getTimestamp(),
        ];

        return $token;
    }

    protected static function booted(): void
    {
        static::deleting(function (GithubApp $github_app) {
            $applications_count = $github_app->applications()->count();
            if ($applications_count > 0) {
                throw new \Exception('You cannot delete this GitHub App because it is in use by '.$applications_count.' application(s). Delete them first.');
            }

            // Runner rows cascade with the App, so live runner containers would lose their cleanup and keep taking jobs.
            $hasLiveRunners = $github_app->runnerConfigs()->where('is_enabled', true)->exists()
                || GithubRunnerExecution::where('github_app_id', $github_app->id)
                    ->whereIn('status', GithubRunnerStatus::occupying())
                    ->exists();
            if ($hasLiveRunners) {
                throw new \Exception('You cannot delete this GitHub App because servers use it for GitHub Actions runners. Disable the runners on these servers and wait for running jobs to finish first.');
            }

            $privateKey = $github_app->privateKey;
            if ($privateKey) {
                // Check if key is used by anything EXCEPT this GitHub app
                $isUsedElsewhere = $privateKey->servers()->exists()
                    || $privateKey->applications()->exists()
                    || $privateKey->githubApps()->where('id', '!=', $github_app->id)->exists()
                    || $privateKey->gitlabApps()->exists()
                    || $privateKey->giteaApps()->exists();

                if (! $isUsedElsewhere) {
                    $privateKey->delete();
                } else {
                }
            }
        });
    }

    public static function ownedByCurrentTeam()
    {
        return GithubApp::where(function ($query) {
            $query->where('team_id', currentTeam()->id)
                ->orWhere('is_system_wide', true);
        });
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function runnerConfigs()
    {
        return $this->hasMany(GithubRunnerConfig::class);
    }

    /**
     * Permissions and webhook events that GitHub Actions runners need but the App does not have.
     *
     * @return array<int, string>
     */
    public function missingRunnerRequirements(): array
    {
        $missing = [];
        if (blank($this->organization)) {
            $missing[] = 'An organization (runners are registered at organization level)';
        }
        if ($this->organization_self_hosted_runners !== 'write') {
            $missing[] = 'Organization permission "Self-hosted runners": write';
        }
        if (! in_array($this->actions, ['read', 'write'], true)) {
            $missing[] = 'Repository permission "Actions": read';
        }
        if (! in_array('workflow_job', $this->webhook_events ?? [], true)) {
            $missing[] = 'Webhook event "Workflow job"';
        }

        return $missing;
    }

    public function type(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->getMorphClass() === GithubApp::class) {
                    return 'github';
                }
            },
        );
    }

    /**
     * A private GitHub App is connected once it has been registered and installed.
     * Public sources do not require installation credentials.
     */
    public function isConnected(): bool
    {
        if ($this->is_public) {
            return true;
        }

        return filled($this->app_id) && filled($this->installation_id);
    }

    /**
     * The settings page of this App at GitHub.
     */
    public function settingsUrl(): string
    {
        $name = encodeGithubPathSegment($this->name);
        $organization = normalizeGithubOrganization($this->organization);

        if (filled($organization)) {
            return rtrim($this->html_url, '/').'/organizations/'.encodeGithubPathSegment($organization)."/settings/apps/{$name}";
        }

        return rtrim($this->html_url, '/')."/settings/apps/{$name}";
    }
}
