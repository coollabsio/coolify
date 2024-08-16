<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScheduledTask extends BaseModel
{
    protected $guarded = [];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function latest_log(): HasOne
    {
        return $this->hasOne(ScheduledTaskExecution::class)->latest();
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ScheduledTaskExecution::class);
    }

    public function server()
    {
        if ($this->application) {
            return $this->application->server;
        } elseif ($this->database) {
            return $this->database->server;
        } elseif ($this->service) {
            return $this->service->server;
        }
        return null;
    }
}