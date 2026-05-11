<?php

namespace App\Models;

use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'server_id',

        // Boolean flags
        'is_multiline',
        'is_literal',
        'is_shown_once',

        // Metadata
        'version',
    ];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
    ];

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => ValidationPatterns::validatedEnvironmentVariableKey($value),
        );
    }

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

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
