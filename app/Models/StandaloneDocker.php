<?php

namespace App\Models;

class StandaloneDocker extends BaseModel
{
    public function applications()
    {
        return $this->morphMany(Application::class, 'destination');
    }
}
