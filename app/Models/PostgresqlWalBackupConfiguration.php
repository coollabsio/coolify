<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostgresqlWalBackupConfiguration extends BaseModel
{
    protected $fillable = [
        'uuid',
        'team_id',
        'standalone_postgresql_id',
        's3_storage_id',
        'enabled',
        'base_backup_frequency',
        'archive_timeout_seconds',
        'wal_level',
        'retention_full_backups',
        'timeout',
        'postgres_major_version',
        'status',
        'last_health_message',
        'last_archived_wal',
        'last_archived_at',
        'last_failed_wal',
        'last_failed_at',
        'last_failed_count',
        'last_base_backup_at',
        'last_successful_base_backup_at',
    ];

    protected $attributes = [
        'enabled' => true,
        'base_backup_frequency' => '0 3 * * *',
        'archive_timeout_seconds' => 60,
        'wal_level' => 'replica',
        'retention_full_backups' => 7,
        'timeout' => 3600,
        'status' => 'warning',
        'last_failed_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'archive_timeout_seconds' => 'integer',
            'retention_full_backups' => 'integer',
            'timeout' => 'integer',
            'postgres_major_version' => 'integer',
            'last_archived_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_failed_count' => 'integer',
            'last_base_backup_at' => 'datetime',
            'last_successful_base_backup_at' => 'datetime',
        ];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(StandalonePostgresql::class, 'standalone_postgresql_id');
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
        return $this->hasMany(PostgresqlWalBackupExecution::class)->latest();
    }

    public function hasVerifiedArchivingHealth(): bool
    {
        if ($this->last_successful_base_backup_at) {
            return true;
        }

        return $this->executions()
            ->where('operation', 'health_check')
            ->where('status', 'success')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $this->updated_at)
            ->exists();
    }
}
