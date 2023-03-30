<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Activitylog\Models\Activity;

class Application extends BaseModel
{
    protected static function booted()
    {
        static::created(function ($application) {
            ApplicationSetting::create([
                'application_id' => $application->id,
            ]);
        });
    }

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

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            get: fn (string|null $portsMappings) =>
            is_null($portsMappings)
                ? []
                : explode(',', $portsMappings)

        );
    }
    public function portsExposes(): Attribute
    {
        return Attribute::make(
            get: fn (string|null $portsExposes) =>
            is_null($portsExposes)
                ? []
                : explode(',', $portsExposes)

        );
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
