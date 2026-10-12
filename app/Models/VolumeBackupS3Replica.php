<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The copy of one volume or directory backup execution in one S3 destination.
 */
class VolumeBackupS3Replica extends Model
{
    protected $fillable = [
        'execution_id',
        's3_storage_id',
        's3_uploaded',
        's3_storage_deleted',
        'message',
    ];

    protected function casts(): array
    {
        return [
            's3_uploaded' => 'boolean',
            's3_storage_deleted' => 'boolean',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(ScheduledVolumeBackupExecution::class, 'execution_id');
    }

    public function s3(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }
}
