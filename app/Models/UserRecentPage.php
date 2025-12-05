<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecentPage extends Model
{
    protected $fillable = [
        'user_id',
        'team_id',
        'pages',
    ];

    protected $casts = [
        'pages' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function recordVisit(int $userId, int $teamId, string $url, string $label, ?string $sublabel = null): void
    {
        $record = self::firstOrCreate(
            ['user_id' => $userId, 'team_id' => $teamId],
            ['pages' => []]
        );

        $pages = collect($record->pages);

        // Find existing entry to preserve pin status
        $existing = $pages->firstWhere('url', $url);
        $isPinned = $existing['pinned'] ?? false;
        $pinnedAt = $existing['pinned_at'] ?? null;

        // Remove existing entry for this URL (if any)
        $pages = $pages->reject(fn ($p) => $p['url'] === $url);

        // Separate pinned and unpinned
        $pinned = $pages->filter(fn ($p) => ! empty($p['pinned']));
        $unpinned = $pages->reject(fn ($p) => ! empty($p['pinned']));

        // Create new entry
        $newEntry = [
            'url' => $url,
            'label' => $label,
            'sublabel' => $sublabel,
            'visited_at' => now()->toISOString(),
            'pinned' => $isPinned,
            'pinned_at' => $pinnedAt,
        ];

        if ($isPinned) {
            // If pinned, add back to pinned list (preserve position by pinned_at)
            $pinned->push($newEntry);
            $pinned = $pinned->sortByDesc('pinned_at')->values();
        } else {
            // If not pinned, prepend to unpinned list
            // Keep up to 10 unpinned for backfill when items are pinned
            $unpinned->prepend($newEntry);
            $unpinned = $unpinned->take(10)->values();
        }

        // Merge: pinned first (max 5), then unpinned (max 10 stored for backfill)
        $record->pages = $pinned->take(5)->merge($unpinned->take(10))->values()->all();
        $record->save();
    }

    public static function togglePin(int $userId, int $teamId, string $url): bool
    {
        $record = self::where('user_id', $userId)
            ->where('team_id', $teamId)
            ->first();

        if (! $record) {
            return false;
        }

        $pages = collect($record->pages);
        $index = $pages->search(fn ($p) => $p['url'] === $url);

        if ($index === false) {
            return false;
        }

        $page = $pages[$index];
        $currentlyPinned = $page['pinned'] ?? false;

        // Check if we can pin (max 5 pinned)
        if (! $currentlyPinned) {
            $pinnedCount = $pages->filter(fn ($p) => ! empty($p['pinned']))->count();
            if ($pinnedCount >= 5) {
                return false; // Can't pin more
            }
        }

        $page['pinned'] = ! $currentlyPinned;
        $page['pinned_at'] = $page['pinned'] ? now()->toISOString() : null;

        $pages[$index] = $page;

        // Re-sort: pinned first (by pinned_at desc), then unpinned (by visited_at desc)
        $pinned = $pages->filter(fn ($p) => ! empty($p['pinned']))->sortByDesc('pinned_at');
        $unpinned = $pages->reject(fn ($p) => ! empty($p['pinned']))->sortByDesc('visited_at');

        $record->pages = $pinned->merge($unpinned)->values()->all();
        $record->save();

        return $page['pinned'];
    }

    public static function getRecent(int $userId, int $teamId): array
    {
        $record = self::where('user_id', $userId)
            ->where('team_id', $teamId)
            ->first();

        if (! $record?->pages) {
            return [];
        }

        $pages = collect($record->pages);

        // Separate pinned and unpinned
        $pinned = $pages->filter(fn ($p) => ! empty($p['pinned']))->take(5);
        $unpinned = $pages->reject(fn ($p) => ! empty($p['pinned']))->take(5);

        // Return max 5 pinned + max 5 unpinned = max 10 displayed
        return $pinned->merge($unpinned)->values()->all();
    }
}
