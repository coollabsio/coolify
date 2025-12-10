<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visus\Cuid2\Cuid2;

class PgbackrestRepo extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'repo_number' => 'integer',
            'retention_full' => 'integer',
            'retention_diff' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($repo) {
            if (empty($repo->uuid)) {
                $repo->uuid = (string) new Cuid2;
            }
        });
    }

    public function scheduledDatabaseBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackup::class);
    }

    public function s3Storage(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function isLocal(): bool
    {
        return $this->type === 'posix';
    }

    public function isS3(): bool
    {
        return $this->type === 's3';
    }

    public function getRepoKey(): string
    {
        return "repo{$this->repo_number}";
    }

    public function getDefaultPath(): string
    {
        if ($this->isS3()) {
            $backup = $this->scheduledDatabaseBackup;
            $database = $backup?->database;

            return '/'.($database?->uuid ?? 'backup');
        }

        return '/var/lib/pgbackrest';
    }

    public function getEffectivePath(): string
    {
        return $this->path ?: $this->getDefaultPath();
    }
}
