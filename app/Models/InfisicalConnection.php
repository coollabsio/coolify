<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfisicalConnection extends BaseModel
{
    use HasFactory;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'team_id',
        'name',
        'host',
        'client_id',
        'client_secret',
        'infisical_project_id',
        'is_enabled',
        'adopted_at',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
    ];

    protected $hidden = [
        'client_id',
        'client_secret',
    ];

    /**
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Trim pasted credentials before saving. The saving event is used rather
        // than Attribute mutators because client_id/client_secret use the
        // 'encrypted' cast, and mutators fire before casts.
        static::saving(function (InfisicalConnection $connection): void {
            if ($connection->client_id !== null) {
                $connection->client_id = trim($connection->client_id);
            }
            if ($connection->client_secret !== null) {
                $connection->client_secret = trim($connection->client_secret);
            }
            if ($connection->host !== null) {
                $connection->host = rtrim(trim($connection->host), '/');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'is_enabled' => 'boolean',
            'adopted_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Deep link to this project's secrets in Infisical, optionally narrowed to
     * one native environment. Presentation only — it is never the control.
     */
    public function secretsUrl(?string $environmentSlug = null): ?string
    {
        if (blank($this->host) || blank($this->infisical_project_id)) {
            return null;
        }

        $url = rtrim($this->host, '/')."/project/{$this->infisical_project_id}/secrets";

        return blank($environmentSlug) ? $url : $url."/{$environmentSlug}";
    }

    /**
     * The team's enabled connection, or null. Used by the read-only surfaces to
     * render a badge and a deep link.
     */
    public static function enabledForTeam(?int $teamId): ?self
    {
        if ($teamId === null) {
            return null;
        }

        return static::query()
            ->where('team_id', $teamId)
            ->where('is_enabled', true)
            ->first();
    }

    /**
     * Scope connections to the acting team.
     */
    public static function ownedByCurrentTeam(array $select = ['*']): Builder
    {
        return static::query()->select($select)->where('team_id', currentTeam()->id);
    }
}
