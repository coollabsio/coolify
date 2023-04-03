<?php

namespace App\Models;

class LocalPersistentVolume extends BaseModel
{
    public function application()
    {
        return $this->morphTo();
    }
}
