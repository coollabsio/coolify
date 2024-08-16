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
        ray('Entering server() method in ScheduledTask model');
        
        if ($this->application) {
            ray('Returning server from application');
            $server = $this->application->server;
            ray('Returning server from application: '.$server);
            return $server;
        } 
        elseif ($this->database) {
            ray('Returning server from database');
            $server = $this->database->server;
            ray('Returning server from database: '.$server);
            return $server;
        } elseif ($this->service) {
            ray('Returning server from service');
            $server = $this->service->server;
            ray('Returning server from service: '.$server);
            return $server;
        }
        
        ray('No server found, returning null');
        return null;
    }
}