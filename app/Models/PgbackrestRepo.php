<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PgbackrestRepo extends Model
{
    protected $guarded = [];

    protected $casts = [
        'retention_full' => 'integer',
        'retention_diff' => 'integer',
        'retention_archive' => 'integer',
        'repo_index' => 'integer',
    ];

    protected static function booted()
    {
        static::creating(function (PgbackrestRepo $repo) {
            if (! $repo->repo_index) {
                $used = self::where('standalone_postgresql_id', $repo->standalone_postgresql_id)
                    ->pluck('repo_index')
                    ->all();

                for ($i = 1; $i <= 8; $i++) {
                    if (! in_array($i, $used, true)) {
                        $repo->repo_index = $i;
                        break;
                    }
                }
            }

            if (! $repo->repo_index || $repo->repo_index < 1 || $repo->repo_index > 8) {
                throw new \InvalidArgumentException('pgBackRest repo_index must be between 1 and 8. Maximum 8 repos per database.');
            }

            if ($repo->type === 's3' && ! $repo->s3_storage_id) {
                throw new \InvalidArgumentException('S3 pgBackRest repo must reference an S3Storage.');
            }
        });
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(StandalonePostgresql::class, 'standalone_postgresql_id');
    }

    public function s3Storage(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class);
    }

    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(
            ScheduledDatabaseBackup::class,
            'pgbackrest_repo_scheduled_backup'
        );
    }

    public function getRetentionFullEffectiveAttribute(): int
    {
        return $this->retention_full
            ?? $this->database->pgbackrest_retention_full
            ?? config('constants.pgbackrest.default_retention_full', 2);
    }

    public function getRetentionDiffEffectiveAttribute(): int
    {
        return $this->retention_diff
            ?? $this->database->pgbackrest_retention_diff
            ?? config('constants.pgbackrest.default_retention_diff', 7);
    }

    public function getRetentionFullTypeEffectiveAttribute(): string
    {
        return $this->retention_full_type
            ?? $this->database->pgbackrest_retention_full_type
            ?? 'count';
    }

    public function getRetentionArchiveEffectiveAttribute(): ?int
    {
        return $this->retention_archive
            ?? $this->database->pgbackrest_retention_archive;
    }

    public function getRetentionArchiveTypeEffectiveAttribute(): string
    {
        return $this->retention_archive_type
            ?? $this->database->pgbackrest_retention_archive_type
            ?? 'full';
    }

    public function isS3(): bool
    {
        return $this->type === 's3';
    }

    public function isPosix(): bool
    {
        return $this->type === 'posix';
    }
}
