<?php

namespace App\Models;

class PrivateKey extends BaseModel
{
    public function private_keyables()
    {
        return $this->hasMany(PrivateKeyable::class);
    }

    public function servers()
    {
        return $this->morphedByMany(Server::class, 'private_keyable');
    }
}
