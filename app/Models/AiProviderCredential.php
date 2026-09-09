<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiProviderCredential extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'provider',
        'model',
        'api_key',
        'base_url',
        'is_default',
        'enabled',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'provider' => AiProvider::class,
        'api_key' => 'encrypted',
        'is_default' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }

    public static function defaultForTeam(int $teamId): ?self
    {
        return self::where('team_id', $teamId)
            ->where('enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    public function makeDefault(): void
    {
        static::where('team_id', $this->team_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
    }
}
