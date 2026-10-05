<?php

namespace App\Models;

use App\Enums\GithubRunnerStatus;
use App\Jobs\DeregisterGithubRunnerJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubRunnerExecution extends BaseModel
{
    protected $fillable = [
        'github_app_id',
        'github_runner_config_id',
        'server_id',
        'status',
        'trigger_workflow_job_id',
        'workflow_job_id',
        'workflow_job_html_url',
        'workflow_name',
        'job_name',
        'repository_full_name',
        'labels',
        'is_pull_request',
        'runner_name',
        'runner_id',
        'conclusion',
        'error_message',
        'provision_attempts',
        'queued_at',
        'ready_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GithubRunnerStatus::class,
            'trigger_workflow_job_id' => 'integer',
            'workflow_job_id' => 'integer',
            'runner_id' => 'integer',
            'provision_attempts' => 'integer',
            'labels' => 'array',
            'is_pull_request' => 'boolean',
            'queued_at' => 'datetime',
            'ready_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(GithubRunnerConfig::class, 'github_runner_config_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Deregistering the runners from GitHub is best effort and never blocks the deletion.
     *
     * @param  Builder<GithubRunnerExecution>  $query
     */
    public static function deleteAndDeregister(Builder $query): void
    {
        (clone $query)
            ->whereIn('status', GithubRunnerStatus::occupying())
            ->whereNotNull('runner_id')
            ->get(['github_app_id', 'runner_id'])
            ->each(fn (GithubRunnerExecution $execution) => DeregisterGithubRunnerJob::dispatch($execution->github_app_id, $execution->runner_id));

        (clone $query)->delete();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Base name of the Docker resources (containers, network, volumes) of this runner.
     */
    public function containerName(): string
    {
        return 'coolify-runner-'.$this->uuid;
    }

    /**
     * Marks the runner as finished and keeps the first completion time.
     */
    public function finish(GithubRunnerStatus $status, ?string $errorMessage = null): void
    {
        $this->update([
            'status' => $status,
            'error_message' => $errorMessage,
            'completed_at' => $this->completed_at ?? now(),
        ]);
    }

    public function duration(): ?string
    {
        if (! $this->started_at) {
            return null;
        }

        return $this->started_at->diffForHumans($this->completed_at ?? now(), true);
    }

    public function workflowJobUrl(): ?string
    {
        $url = trim((string) $this->workflow_job_html_url);

        return str_starts_with($url, 'https://') ? $url : null;
    }
}
