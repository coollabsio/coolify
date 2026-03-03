<?php

namespace App\Models;

use App\Enums\GithubRunnerStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubRunnerExecution extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GithubRunnerStatus::class,
            'workflow_job_id' => 'integer',
            'runner_id' => 'integer',
            'pid' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(GithubRunnerConfig::class, 'github_runner_config_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function duration(): ?string
    {
        if (! $this->started_at) {
            return null;
        }

        $end = $this->completed_at ?? now();

        return $this->started_at->diffForHumans($end, true);
    }
}
