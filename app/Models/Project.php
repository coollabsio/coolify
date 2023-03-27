<?php

namespace App\Models;

class Project extends BaseModel
{
    public function environments() {
        return $this->hasMany(Environment::class);
    }
    public function settings() {
        return $this->hasOne(ProjectSetting::class);
    }
}
