<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostgresqlWalBackupExecution extends BaseModel
{
    protected $fillable = [
        'uuid',
        'postgresql_wal_backup_configuration_id',
        'operation',
        'status',
        'message',
        'backup_name',
        'target_time',
        'restored_database_id',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'running',
    ];

    protected static function booted(): void
    {
        static::creating(function (PostgresqlWalBackupExecution $execution): void {
            $execution->started_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'target_time' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(PostgresqlWalBackupConfiguration::class, 'postgresql_wal_backup_configuration_id');
    }

    public function restoredDatabase(): BelongsTo
    {
        return $this->belongsTo(StandalonePostgresql::class, 'restored_database_id');
    }
}
