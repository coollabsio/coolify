<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InfisicalConnection extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'host',
        'client_id',
        'client_secret',
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
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(InfisicalBinding::class);
    }

    /**
     * Scope connections to the acting team.
     */
    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        return static::query()->select($select)->where('team_id', currentTeam()->id);
    }
}
