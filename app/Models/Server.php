<?php

namespace App\Models;

class Server extends BaseModel
{
    public function private_key()
    {
        return $this->morphMany(PrivateKey::class, 'private_key_morph');
    }
}
