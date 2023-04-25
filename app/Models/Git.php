<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Git extends Model
{
    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }
}
