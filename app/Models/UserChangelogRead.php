<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserChangelogRead extends Model
{
    protected $fillable = [
        'user_id',
        'release_tag',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function markAsRead(int $userId, string $identifier): void
    {
        self::firstOrCreate([
            'user_id' => $userId,
            'release_tag' => $identifier,
        ], [
            'read_at' => now(),
        ]);
    }

    public static function isReadByUser(int $userId, string $identifier): bool
    {
        return self::where('user_id', $userId)
            ->where('release_tag', $identifier)
            ->exists();
    }

    public static function getReadIdentifiersForUser(int $userId): array
    {
        return self::where('user_id', $userId)
            ->pluck('release_tag')
            ->toArray();
    }
}
