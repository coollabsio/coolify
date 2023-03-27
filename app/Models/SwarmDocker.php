<?php

namespace App\Models;

class SwarmDocker extends BaseModel
{
    public function applications()
    {
        return $this->morphMany(Application::class, 'destination');
    }
}
