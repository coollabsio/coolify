<?php

namespace App\Models;

class Database extends BaseModel
{
    public function environments()
    {
        return $this->morphToMany(Environment::class, 'environmentable');
    }
}
