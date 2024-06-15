<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property string|null $message
 * @property string|null $size
 * @property string|null $filename
 * @property int $scheduled_database_backup_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $database_name
 * @property-read \App\Models\ScheduledDatabaseBackup|null $scheduledDatabaseBackup
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution query()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereDatabaseName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereScheduledDatabaseBackupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackupExecution whereUuid($value)
 *
 * @mixin \Eloquent
 */
class ScheduledDatabaseBackupExecution extends BaseModel
{
    protected $guarded = [];

    public function scheduledDatabaseBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackup::class);
    }
}
