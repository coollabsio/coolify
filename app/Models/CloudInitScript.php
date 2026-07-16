<?php

namespace App\Models;

class CloudInitScript extends BaseModel
{
    protected $fillable = [
        'team_id',
        'name',
        'script',
    ];

    protected $hidden = [
        'script',
    ];

    protected function casts(): array
    {
        return [
            'script' => 'encrypted',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }
}
