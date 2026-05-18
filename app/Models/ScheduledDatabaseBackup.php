<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledDatabaseBackup extends BaseModel
{
    protected static function booted(): void
    {
        static::deleting(function (ScheduledDatabaseBackup $backup): void {
            $backup->executions()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'database_backup_retention_max_storage_locally' => 'float',
            'database_backup_retention_max_storage_s3' => 'float',
        ];
    }

    protected $fillable = [
        'uuid',
        'team_id',
        'description',
        'enabled',
        'save_s3',
        'frequency',
        'database_backup_retention_amount_locally',
        'database_type',
        'database_id',
        's3_storage_id',
        'databases_to_backup',
        'dump_all',
        'database_backup_retention_days_locally',
        'database_backup_retention_max_storage_locally',
        'database_backup_retention_amount_s3',
        'database_backup_retention_days_s3',
        'database_backup_retention_max_storage_s3',
        'timeout',
        'disable_local_backup',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'save_s3' => 'boolean',
            'dump_all' => 'boolean',
            'disable_local_backup' => 'boolean',
            'pgbackrest_compress_level' => 'integer',
        ];
    }

    public function isPgBackrest(): bool
    {
        return $this->engine === 'pgbackrest';
    }

    public function isNative(): bool
    {
        return $this->engine === 'native' || $this->engine === null;
    }

    public function pgbackrestRepos(): HasMany
    {
        return $this->hasMany(PgbackrestRepo::class)->orderBy('repo_number');
    }

    public function enabledPgbackrestRepos(): HasMany
    {
        return $this->hasMany(PgbackrestRepo::class)->where('enabled', true)->orderBy('repo_number');
    }

    public function localRepo(): ?PgbackrestRepo
    {
        return $this->enabledPgbackrestRepos()->where('type', 'posix')->first()
            ?? $this->pgbackrestRepos()->where('type', 'posix')->first();
    }

    public function s3Repo(): ?PgbackrestRepo
    {
        return $this->enabledPgbackrestRepos()->where('type', 's3')->first()
            ?? $this->pgbackrestRepos()->where('type', 's3')->first();
    }

    public function hasLocalRepo(): bool
    {
        return $this->pgbackrestRepos()->where('type', 'posix')->where('enabled', true)->exists();
    }

    public function hasS3Repo(): bool
    {
        return $this->pgbackrestRepos()->where('type', 's3')->where('enabled', true)->exists();
    }

    public function restores(): HasManyThrough
    {
        return $this->hasManyThrough(
            DatabaseRestore::class,
            ScheduledDatabaseBackupExecution::class,
            'scheduled_database_backup_id',
            'scheduled_database_backup_execution_id'
        );
    }

    public static function ownedByCurrentTeam()
    {
        return ScheduledDatabaseBackup::whereRelation('team', 'id', currentTeam()->id)->orderBy('created_at', 'desc');
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ScheduledDatabaseBackup::whereRelation('team', 'id', $teamId)->orderBy('created_at', 'desc');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

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
        // Last execution first
        return $this->hasMany(ScheduledDatabaseBackupExecution::class)->orderBy('created_at', 'desc');
    }

    public function s3()
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function get_last_days_backup_status($days = 7)
    {
        return $this->hasMany(ScheduledDatabaseBackupExecution::class)->where('created_at', '>=', now()->subDays($days))->get();
    }

    public function executionsPaginated(int $skip = 0, int $take = 10)
    {
        $executions = $this->hasMany(ScheduledDatabaseBackupExecution::class)->orderBy('created_at', 'desc');
        $count = $executions->count();
        $executions = $executions->skip($skip)->take($take)->get();

        return [
            'count' => $count,
            'executions' => $executions,
        ];
    }

    public function server(): ?Server
    {
        if ($this->database) {
            $server = null;
            if ($this->database instanceof ServiceDatabase) {
                $server = $this->database->server;
            } else {
                $destination = data_get($this->database, 'destination');
                $server = data_get($destination, 'server');
            }
            if ($server) {
                return $server;
            }
        }

        return null;
    }
}
