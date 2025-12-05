<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecentVisit extends Model
{
    protected $fillable = [
        'user_id',
        'url',
        'title',
        'subtitle',
        'type',
        'icon',
        'visited_at',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a visit for a user, updating if already exists.
     */
    public static function recordVisit(
        int $userId,
        string $url,
        string $title,
        string $type,
        ?string $subtitle = null,
        ?string $icon = null
    ): self {
        return static::updateOrCreate(
            [
                'user_id' => $userId,
                'url' => $url,
            ],
            [
                'title' => $title,
                'subtitle' => $subtitle,
                'type' => $type,
                'icon' => $icon,
                'visited_at' => now(),
            ]
        );
    }

    /**
     * Get recent visits for a user, limited to a specific count.
     */
    public static function getRecentForUser(int $userId, int $limit = 5): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('user_id', $userId)
            ->orderByDesc('visited_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Cleanup old visits, keeping only the most recent ones.
     */
    public static function cleanupOldVisits(int $userId, int $keepCount = 10): void
    {
        $idsToKeep = static::where('user_id', $userId)
            ->orderByDesc('visited_at')
            ->limit($keepCount)
            ->pluck('id');

        static::where('user_id', $userId)
            ->whereNotIn('id', $idsToKeep)
            ->delete();
    }
}
