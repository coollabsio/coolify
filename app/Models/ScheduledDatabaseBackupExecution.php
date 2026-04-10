<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledDatabaseBackupExecution extends BaseModel
{
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
        ];
    }

    public function scheduledDatabaseBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackup::class);
    }
}
