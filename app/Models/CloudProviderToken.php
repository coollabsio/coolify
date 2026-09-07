<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class CloudProviderToken extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'provider',
        'token',
        'name',
    ];

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

    public function cloudflareDnsServerSettings()
    {
        return $this->hasMany(ServerSetting::class, 'cloudflare_dns_token_id');
    }

    public function hasServers(): bool
    {
        return $this->servers()->exists();
    }

    public function isUsed(): bool
    {
        return $this->hasServers() || $this->cloudflareDnsServerSettings()->exists();
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
