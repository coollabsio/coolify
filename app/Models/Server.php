<?php

namespace App\Models;

class Server extends BaseModel
{
    public function privateKeys()
    {
        return $this->morphToMany(PrivateKey::class, 'private_keyable');
    }
}
