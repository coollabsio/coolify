<?php

namespace App\Models;

class Server extends BaseModel
{
    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }
}
