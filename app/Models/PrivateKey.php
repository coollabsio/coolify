<?php

namespace App\Models;

class PrivateKey extends BaseModel
{
    public function private_key_morph()
    {
        return $this->morphTo();
    }
}
