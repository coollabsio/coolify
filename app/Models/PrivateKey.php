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
    protected $hidden = [
        'private_key',
    ];
    static public function ownedByCurrentTeam()
    {
        return PrivateKey::whereTeamId(session('currentTeam')->id);
    }
    public function servers()
    {
        return $this->hasMany(Server::class);
    }
}
