<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationToken extends BaseModel
{
    protected $fillable = [
        'team_id',
        'provider',
        'name',
        'token',
        'capabilities',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'capabilities' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function ownedByCurrentTeam()
    {
        return self::query()->where('team_id', currentTeam()->id);
    }
}
