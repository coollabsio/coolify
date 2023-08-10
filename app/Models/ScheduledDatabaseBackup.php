<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
}
