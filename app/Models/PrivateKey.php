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
    static public function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);
        return PrivateKey::whereTeamId(session('currentTeam')->id)->where('id', '>', 0)->select($selectArray->all());
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }
}
