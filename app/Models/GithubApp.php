<?php

namespace App\Models;

class GithubApp extends BaseModel
{
    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }
}
