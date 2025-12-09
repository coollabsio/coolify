<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visus\Cuid2\Cuid2;

class ScheduledDatabaseBackupExecution extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            's3_uploaded' => 'boolean',
            'local_storage_deleted' => 'boolean',
            's3_storage_deleted' => 'boolean',
            'pgbackrest_repo_indexes' => 'array',
        ];
    }

    public static function generateUniqueUuid(int $maxAttempts = 3): string
    {
        $attempts = 0;
        do {
            $uuid = (string) new Cuid2;
            $exists = self::where('uuid', $uuid)->exists();
            $attempts++;
            if ($attempts >= $maxAttempts && $exists) {
                throw new \Exception('Unable to generate unique UUID for backup execution after '.$maxAttempts.' attempts');
            }
        } while ($exists);

        return $uuid;
    }

    public function scheduledDatabaseBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackup::class);
    }
}
