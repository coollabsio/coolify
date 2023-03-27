<?php

namespace App\Models;

class Database extends BaseModel
{
    public function environment()
    {
        return $this->morphTo();
    }
    public function destination()
    {
        return $this->morphTo();
    }
}
