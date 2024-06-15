<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string|null $description
 * @property string $uuid
 * @property bool $enabled
 * @property bool $save_s3
 * @property string $frequency
 * @property int $number_of_backups_locally
 * @property string $database_type
 * @property int $database_id
 * @property int|null $s3_storage_id
 * @property int $team_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $databases_to_backup
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $database
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ScheduledDatabaseBackupExecution> $executions
 * @property-read int|null $executions_count
 * @property-read \App\Models\ScheduledDatabaseBackupExecution|null $latest_log
 * @property-read \App\Models\S3Storage|null $s3
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup query()
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereDatabaseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereDatabaseType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereDatabasesToBackup($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereFrequency($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereNumberOfBackupsLocally($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereS3StorageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereSaveS3($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ScheduledDatabaseBackup whereUuid($value)
 *
 * @mixin \Eloquent
 */
class ScheduledDatabaseBackup extends BaseModel
{
    protected $guarded = [];

    public function database(): MorphTo
    {
        return $this->morphTo();
    }

    public function latest_log(): HasOne
    {
        return $this->hasOne(ScheduledDatabaseBackupExecution::class)->latest();
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ScheduledDatabaseBackupExecution::class);
    }

    public function s3()
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function get_last_days_backup_status($days = 7)
    {
        return $this->hasMany(ScheduledDatabaseBackupExecution::class)->where('created_at', '>=', now()->subDays($days))->get();
    }
}
