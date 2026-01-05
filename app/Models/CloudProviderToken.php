<?php

namespace App\Models;

class CloudProviderToken extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'token' => 'encrypted',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function hasServers(): bool
    {
        return $this->servers()->exists();
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
