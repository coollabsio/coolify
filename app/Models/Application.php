<?php

namespace App\Models;

class Application extends BaseModel
{
    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }
    public function destination()
    {
        return $this->morphTo();
    }
    public function source()
    {
        return $this->morphTo();
    }
}
