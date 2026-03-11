<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedEnvironmentVariable extends Model
{
    protected $fillable = [
        // Core identification
        'key',
        'value',
        'comment',

        // Type and relationships
        'type',
        'team_id',
        'project_id',
        'environment_id',

        // Boolean flags
        'is_multiline',
        'is_literal',
        'is_shown_once',
    ];

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
