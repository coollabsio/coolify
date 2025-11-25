<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class GitHubRunnerSource extends BaseModel
{
    protected $fillable = [
        'team_id',
        'name',
        'runner_label',
        'app_id',
        'installation_id',
        'client_id',
        'client_secret',
        'webhook_secret',
        'organization',
        'is_organization_level',
        'permissions',
    ];

    protected $hidden = [
        'client_secret',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_organization_level' => 'boolean',
            'permissions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (GitHubRunnerSource $source) {
            $runnersCount = $source->runners()->where('status', 'running')->count();
            if ($runnersCount > 0) {
                throw new \Exception('You cannot delete this runner source because it has '.$runnersCount.' active runner(s). Wait for them to complete first.');
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'github_runner_servers')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    public function runners(): HasMany
    {
        return $this->hasMany(GitHubRunner::class);
    }

    public function getAvailableServers(): Collection
    {
        return $this->servers()
            ->wherePivot('is_active', true)
            ->withCount(['runners' => fn ($query) => $query->where('status', 'running')])
            ->orderBy('runners_count', 'asc')
            ->get();
    }

    public function generateJwtToken(): string
    {
        $now = time();
        $payload = [
            'iat' => $now,
            'exp' => $now + 600,
            'iss' => $this->app_id,
        ];

        $privateKey = config('coolify.github_app_private_key');

        return \Firebase\JWT\JWT::encode($payload, $privateKey, 'RS256');
    }

    public function generateInstallationToken(): ?string
    {
        try {
            $jwt = $this->generateJwtToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$jwt,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->post("https://api.github.com/app/installations/{$this->installation_id}/access_tokens");

            if ($response->successful()) {
                return $response->json()['token'];
            }

            return null;
        } catch (\Exception $e) {
            ray($e->getMessage());

            return null;
        }
    }

    public function getWebhookUrl(): string
    {
        $baseUrl = config('app.url');

        return "{$baseUrl}/webhooks/github-runner/{$this->id}";
    }
}
