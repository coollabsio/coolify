<?php

namespace App\Models;

use App\Traits\HasS3Replicas;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledVolumeBackupExecution extends BaseModel
{
    use HasS3Replicas;

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
        'recovery_last_attempt_at',
        'recovery_error',
        'recovery_needs_attention',
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
            'recovery_last_attempt_at' => 'datetime',
            'recovery_needs_attention' => 'boolean',
        ];
    }

    public function hasPendingRecovery(): bool
    {
        return $this->stop_recovery_pending || $this->s3_cleanup_pending;
    }

    public function scheduledVolumeBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledVolumeBackup::class);
    }

    public function s3(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function s3Replicas(): HasMany
    {
        return $this->hasMany(VolumeBackupS3Replica::class, 'execution_id');
    }
}
