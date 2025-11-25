<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubRunner extends BaseModel
{
    protected $fillable = [
        'github_runner_source_id',
        'server_id',
        'runner_id',
        'runner_name',
        'container_name',
        'job_id',
        'workflow_name',
        'repository_name',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(GitHubRunnerSource::class, 'github_runner_source_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    public function markAsRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);
    }
}
