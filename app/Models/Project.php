<?php

namespace App\Models;

class Project extends BaseModel
{
    protected $with = ['settings', 'environments'];
    protected static function booted()
    {
        static::created(function ($project) {
            ProjectSetting::create([
                'project_id' => $project->id,
            ]);
        });
    }
    public function environments() {
        return $this->hasMany(Environment::class);
    }
    public function settings() {
        return $this->hasOne(ProjectSetting::class);
    }
    public function applications() {
        return $this->hasManyThrough(Application::class, Environment::class);
    }
}
