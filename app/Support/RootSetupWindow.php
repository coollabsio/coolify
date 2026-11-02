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

    private const CACHE_KEY = 'root-setup-window';

    public static function openIfNoUserExists(): void
    {
        if (User::query()->exists()) {
            return;
        }

        Cache::put(self::CACHE_KEY, true, now()->addMinutes(self::MINUTES));
    }

    public static function isOpen(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }
}
