<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledVolumeBackupExecution extends BaseModel
{
    protected $fillable = [
        'uuid',
        'scheduled_volume_backup_id',
        's3_storage_id',
        'status',
        'message',
        'size',
        'filename',
        'stop_container_ids',
        'stop_recovery_pending',
        's3_cleanup_pending',
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
            'stop_container_ids' => 'array',
            'stop_recovery_pending' => 'boolean',
            's3_cleanup_pending' => 'boolean',
            'local_storage_deleted' => 'boolean',
            's3_storage_deleted' => 'boolean',
            's3_uploaded' => 'boolean',
        ];
    }

    public function scheduledVolumeBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledVolumeBackup::class);
    }

    public function s3(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }
}
