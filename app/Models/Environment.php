<?php

namespace App\Models;

class Environment extends BaseModel
{
    public function environmentables()
    {
        return $this->hasMany(EnvironmentAble::class);
    }
    public function applications()
    {
        return $this->morphedByMany(Application::class, 'environmentable');
    }
    public function databases()
    {
        return $this->morphedByMany(Database::class, 'environmentable');
    }
}

