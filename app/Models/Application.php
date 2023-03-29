<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

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
        return Activity::where('subject_id', $this->id)->where('properties->deployment_uuid', '!=', null)->orderBy('created_at', 'desc')->get();
    }
    public function get_deployment(string $deployment_uuid)
    {
        return Activity::where('subject_id', $this->id)->where('properties->deployment_uuid', '=', $deployment_uuid)->first();
    }
}
