<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScheduledVolumeBackup extends BaseModel
{
    protected $fillable = [
        'uuid',
        'local_persistent_volume_id',
        'team_id',
        's3_storage_id',
        'frequency',
        'enabled',
        'save_s3',
        'disable_local_backup',
        'stop_during_backup',
        'retention_amount_locally',
        'retention_days_locally',
        'retention_max_storage_locally',
        'retention_amount_s3',
        'retention_days_s3',
        'retention_max_storage_s3',
        'timeout',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'save_s3' => 'boolean',
            'disable_local_backup' => 'boolean',
            'stop_during_backup' => 'boolean',
            'retention_amount_locally' => 'integer',
            'retention_days_locally' => 'integer',
            'retention_max_storage_locally' => 'float',
            'retention_amount_s3' => 'integer',
            'retention_days_s3' => 'integer',
            'retention_max_storage_s3' => 'float',
            'timeout' => 'integer',
        ];
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(LocalPersistentVolume::class, 'local_persistent_volume_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function s3(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ScheduledVolumeBackupExecution::class)->latest();
    }

    public function latestExecution(): HasOne
    {
        return $this->hasOne(ScheduledVolumeBackupExecution::class)->latestOfMany();
    }

    public function server(): ?Server
    {
        $resource = $this->volume?->resource;

        if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            return $resource->service?->server?->fresh();
        }

        return $resource?->destination?->server?->fresh();
    }
}
