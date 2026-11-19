<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A resource that one user starred for quick access on the dashboard.
 */
class Star extends Model
{
    /** Models that a user can star. Each one uses the HasStars trait. */
    public const STARRABLE_TYPES = [
        Server::class,
        Application::class,
        Service::class,
        StandalonePostgresql::class,
        StandaloneMysql::class,
        StandaloneMariadb::class,
        StandaloneMongodb::class,
        StandaloneRedis::class,
        StandaloneKeydb::class,
        StandaloneDragonfly::class,
        StandaloneClickhouse::class,
        StandaloneSqlite::class,
    ];

    protected $fillable = [
        'user_id',
        'starrable_type',
        'starrable_id',
        'position',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function starrable(): MorphTo
    {
        return $this->morphTo();
    }
}
