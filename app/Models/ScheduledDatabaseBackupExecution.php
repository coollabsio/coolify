<?php

namespace App\Models;

use App\Traits\HasS3Replicas;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledDatabaseBackupExecution extends BaseModel
{
    use HasS3Replicas;

    protected static function booted(): void
    {
        static::created(function (ScheduledDatabaseBackupExecution $execution): void {
            $execution->scheduledDatabaseBackup()->update(['last_execution_at' => $execution->created_at ?? now()]);
        });
    }

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
            'size' => 'integer',
            'finished_at' => 'datetime',
            's3_uploaded' => 'boolean',
            'local_storage_deleted' => 'boolean',
            's3_storage_deleted' => 'boolean',
        ];
    }

    public function scheduledDatabaseBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackup::class);
    }

    public function s3Replicas(): HasMany
    {
        return $this->hasMany(DatabaseBackupS3Replica::class, 'execution_id');
    }
}
