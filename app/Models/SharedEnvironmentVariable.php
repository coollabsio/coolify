<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedEnvironmentVariable extends Model
{
    protected $guarded = [];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }
}
