<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledDatabaseBackupExecution extends BaseModel
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'scheduled_database_backup_id',
        'status',
        'message',
        'size',
        'filename',
        'database_name',
        'finished_at',
        'local_storage_deleted',
        's3_storage_deleted',
        's3_uploaded',
    ];

    protected function casts(): array
    {
        return [
            's3_uploaded' => 'boolean',
            'local_storage_deleted' => 'boolean',
            's3_storage_deleted' => 'boolean',
            'pgbackrest_repo_size' => 'integer',
        ];
    }

    public function scheduledDatabaseBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackup::class);
    }

    public function restores(): HasMany
    {
        return $this->hasMany(DatabaseRestore::class, 'scheduled_database_backup_execution_id');
    }

    public function isPgBackrest(): bool
    {
        return $this->engine === 'pgbackrest';
    }

    public function isNative(): bool
    {
        return $this->engine === 'native' || $this->engine === null;
    }

    public function canRestore(): bool
    {
        return $this->isPgBackrest()
            && $this->status === 'success'
            && ! empty($this->pgbackrest_label);
    }
}
