<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DatabaseRestore extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_time' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function database(): MorphTo
    {
        return $this->morphTo();
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackupExecution::class, 'scheduled_database_backup_execution_id');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['success', 'failed']);
    }

    public function appendLog(string $message, bool $persist = true): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $this->log = ($this->log ?? '')."[{$timestamp}] {$message}\n";

        if ($persist) {
            $this->save();
        }
    }

    public function updateStatus(string $status, ?string $message = null): void
    {
        $this->status = $status;

        if ($message !== null) {
            $this->message = $message;
            $this->appendLog($message, persist: false);
        }

        if ($this->isFinished()) {
            $this->finished_at = now();
        }

        $this->save();
    }
}
