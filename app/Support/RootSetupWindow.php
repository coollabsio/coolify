<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The first registration creates the root user. It is allowed only for a short time after
 * Coolify starts, so a bot cannot claim a fresh install. A restart opens the window again.
 */
class RootSetupWindow
{
    public const MINUTES = 10;

    private const CACHE_KEY = 'root-setup-window-closes-at';

    /**
     * The key has no TTL: app:init clears the cache before it calls this, so a missing key
     * means that Coolify has not finished starting, not that the window is closed.
     */
    public static function openIfNoUserExists(): void
    {
        if (User::query()->exists()) {
            return;
        }

        Cache::forever(self::CACHE_KEY, now()->addMinutes(self::MINUTES)->getTimestamp());
    }

    public static function isOpen(): bool
    {
        $closesAt = Cache::get(self::CACHE_KEY);

        return is_int($closesAt) && now()->getTimestamp() < $closesAt;
    }

    public static function isPending(): bool
    {
        return ! Cache::has(self::CACHE_KEY);
    }
}
