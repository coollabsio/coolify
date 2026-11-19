<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The first registration creates the root user. It is allowed only for a short time after
 * Coolify starts, so a bot cannot claim a fresh install. A restart opens the window again.
 * When ROOT_USER_EMAIL and ROOT_USER_PASSWORD are set, only RootUserSeeder creates the root
 * user, so the window never opens.
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
        if (self::isReservedForPredefinedRootUser() || User::query()->exists()) {
            return;
        }

        Cache::forever(self::CACHE_KEY, now()->addMinutes(self::MINUTES)->getTimestamp());
    }

    /**
     * The Redis store returns numbers as strings, so the value is not always an int.
     */
    public static function isOpen(): bool
    {
        if (self::isReservedForPredefinedRootUser()) {
            return false;
        }

        $closesAt = Cache::get(self::CACHE_KEY);

        return is_numeric($closesAt) && now()->getTimestamp() < (int) $closesAt;
    }

    public static function isPending(): bool
    {
        return ! self::isReservedForPredefinedRootUser() && ! Cache::has(self::CACHE_KEY);
    }

    public static function isReservedForPredefinedRootUser(): bool
    {
        return (bool) config('constants.seeder.is_root_user_predefined');
    }
}
