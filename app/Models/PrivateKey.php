<?php

namespace App\Models;

class PrivateKey extends BaseModel
{
    protected $fillable = [
        'name',
        'description',
        'private_key',
        'team_id',
    ];
    public function servers()
    {
        return $this->hasMany(Server::class);
    }
}
