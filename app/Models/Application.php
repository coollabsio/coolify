<?php

namespace App\Models;

class Application extends BaseModel
{
    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }
    public function settings()
    {
        return $this->hasOne(ApplicationSetting::class);
    }
    public function destination()
    {
        return $this->morphTo();
    }
    public function source()
    {
        return $this->morphTo();
    }
    public function deployments()
    {
        return $this->morphMany(Deployment::class, 'type');
    }
}
