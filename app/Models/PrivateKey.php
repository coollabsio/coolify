<?php

namespace App\Models;

class PrivateKey extends BaseModel
{
    public function servers()
    {
        return $this->hasMany(Server::class);
    }
}
