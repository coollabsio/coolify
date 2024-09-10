<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Service;
use App\Models\Application;

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
        // Last execution first
        return $this->hasMany(ScheduledTaskExecution::class)->orderBy('created_at', 'desc');
    }

    public function server()
    {
        if ($this->application) {
            if ($this->application->destination && $this->application->destination->server) {
                $server = $this->application->destination->server;
                return $server;
            }
        } elseif ($this->service) {
            if ($this->service->destination && $this->service->destination->server) {
                $server = $this->service->destination->server;
                return $server;
            }
        } elseif ($this->database) {
            if ($this->database->destination && $this->database->destination->server) {
                $server = $this->database->destination->server;
                return $server;
            }
        }
        return null;
    }
}
