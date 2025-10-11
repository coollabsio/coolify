<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudInitScript extends Model
{
    protected $fillable = [
        'team_id',
        'name',
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
