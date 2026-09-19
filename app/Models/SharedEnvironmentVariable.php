<?php

namespace App\Models;

use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

        // Provenance
        'infisical_binding_id',
    ];

    protected $hidden = [
        'value',
    ];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
    ];

    /**
     * Scope shared environment variables to a team (API token team_id).
     */
    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return static::where('team_id', $teamId);
    }

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

    public function infisicalBinding(): BelongsTo
    {
        return $this->belongsTo(InfisicalBinding::class, 'infisical_binding_id');
    }
}
