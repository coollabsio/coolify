<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledDatabaseBackupExecution extends BaseModel
{
    protected $guarded = [];

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
