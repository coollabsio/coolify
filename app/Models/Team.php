<?php

namespace App\Models;

class Team extends BaseModel
{
    protected $casts = [
        'personal_team' => 'boolean',
    ];
    protected $fillable = [
        'id',
        'name',
        'personal_team'
    ];
    public function projects() {
        return $this->hasMany(Project::class);
    }
    public function servers() {
        return $this->hasMany(Server::class);
    }
    public function applications() {
        return $this->hasManyThrough(Application::class, Project::class);
    }
}
