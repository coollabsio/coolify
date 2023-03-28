<?php

namespace App\Models;

class Environment extends BaseModel
{
    public function project()
    {
        return $this->belongsTo(Project::class);
    }
    public function applications()
    {
        return $this->hasMany(Application::class);
    }
    public function databases()
    {
        return $this->hasMany(Database::class);
    }
    public function services()
    {
        return $this->hasMany(Service::class);
    }
}

