<?php

namespace App\Models;

class Team extends BaseModel
{
    protected $casts = [
        'personal_team' => 'boolean',
    ];
    protected $fillable = [
        'name',
    ];
}
