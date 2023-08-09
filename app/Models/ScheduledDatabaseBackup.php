<?php

namespace App\Models;


class ScheduledDatabaseBackup extends BaseModel
{
    protected $guarded = [];

    public function database()
    {
        return $this->morphTo();
    }
}
