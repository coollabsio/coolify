<?php

namespace App\Models;

class Application extends BaseModel
{
    public function environments()
    {
        return $this->morphToMany(Environment::class, 'environmentable');
    }
}
