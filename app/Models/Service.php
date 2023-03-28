<?php

namespace App\Models;


class Service extends BaseModel
{
    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }
    public function destination()
    {
        return $this->morphTo();
    }
}
