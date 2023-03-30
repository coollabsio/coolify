<?php

namespace App\Models;

class GitlabApp extends BaseModel
{
    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }
}
