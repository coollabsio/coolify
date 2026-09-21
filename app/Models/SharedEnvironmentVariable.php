<?php

namespace App\Models;

use App\Exceptions\InfisicalManagedVariableException;
use App\Services\Infisical\InfisicalLock;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SharedEnvironmentVariable extends Model
{
    use HasFactory;

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
        'is_infisical_managed',
        'infisical_path',
    ];

    protected $hidden = [
        'value',
    ];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
        'is_infisical_managed' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $variable): void {
            self::guardInfisicalLock($variable);
        });

        static::deleting(function (self $variable): void {
            self::guardInfisicalLock($variable);
        });
    }

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

    private static function guardInfisicalLock(self $variable): void
    {
        if (InfisicalLock::isSystemWrite()) {
            return;
        }

        // Server-scoped variables are out of scope per the spec: servers are
        // orthogonal to the project/environment tree, so they are neither
        // synced nor locked. Without this they WOULD be locked, since server
        // rows carry a team_id like every other scope.
        if ($variable->type === 'server') {
            return;
        }

        if (! InfisicalLock::armedForTeam($variable->team_id)) {
            return;
        }

        throw InfisicalManagedVariableException::forKey($variable->key);
    }
}
