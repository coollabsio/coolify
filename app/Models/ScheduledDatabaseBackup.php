<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledDatabaseBackup extends Model
{
    protected $guarded = [];

    public function database()
    {
        return $this->morphTo();
    }
}
