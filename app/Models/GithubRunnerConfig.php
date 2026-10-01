<?php

namespace App\Models;

use App\Enums\GithubRunnerDockerMode;
use App\Enums\GithubRunnerStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubRunnerConfig extends BaseModel
{
    /**
     * Labels that every Coolify runner registers in addition to the custom labels.
     */
    public const DEFAULT_LABELS = ['self-hosted', 'linux'];

    protected $fillable = [
        'server_id',
        'github_app_id',
        'labels',
        'is_enabled',
        'is_dedicated',
        'allow_pull_requests',
        'max_runners',
        'docker_mode',
        'runner_image',
        'cpu_limit',
        'memory_limit',
        'capacity_wait_timeout',
        'idle_timeout',
        'job_timeout',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'is_enabled' => 'boolean',
            'is_dedicated' => 'boolean',
            'allow_pull_requests' => 'boolean',
            'max_runners' => 'integer',
            'docker_mode' => GithubRunnerDockerMode::class,
            'capacity_wait_timeout' => 'integer',
            'idle_timeout' => 'integer',
            'job_timeout' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(GithubRunnerExecution::class);
    }

    /**
     * Labels sent to GitHub when a runner is registered.
     *
     * @return array<int, string>
     */
    public function registeredLabels(): array
    {
        return array_values(array_unique([...self::DEFAULT_LABELS, ...array_map('strtolower', $this->labels ?? [])]));
    }

    /**
     * A job matches when it asks for at least one custom label and every label it asks for is registered.
     * Jobs that only ask for generic labels such as "self-hosted" are left for other runners.
     *
     * @param  array<int, string>  $requestedLabels
     */
    public function matchesLabels(array $requestedLabels): bool
    {
        $requested = array_map(fn ($label) => strtolower((string) $label), $requestedLabels);
        $custom = array_map('strtolower', $this->labels ?? []);

        if (array_intersect($requested, $custom) === []) {
            return false;
        }

        return array_diff($requested, $this->registeredLabels()) === [];
    }

    public function occupiedRunnerCount(): int
    {
        return $this->executions()->whereIn('status', GithubRunnerStatus::occupying())->count();
    }

    public function runnerImage(): string
    {
        return filled($this->runner_image) ? $this->runner_image : config('constants.github_runner.image');
    }
}
