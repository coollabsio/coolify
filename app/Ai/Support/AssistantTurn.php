<?php

namespace App\Ai\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Per-turn ephemeral state (sequence, accumulating partial, cancel flag) keyed
 * by the conversation uuid. Lives in the cache so the streaming job, the UI, and
 * a reconnecting client share it; cleared when the turn finalizes.
 */
class AssistantTurn
{
    private const TTL = 3600;

    public static function nextSequence(string $uuid): int
    {
        $key = "ai:turn:{$uuid}:seq";
        Cache::add($key, 0, self::TTL);

        return (int) Cache::increment($key);
    }

    public static function putPartial(string $uuid, string $text): void
    {
        Cache::put("ai:turn:{$uuid}:partial", $text, self::TTL);
    }

    public static function getPartial(string $uuid): string
    {
        return (string) Cache::get("ai:turn:{$uuid}:partial", '');
    }

    public static function requestStop(string $uuid): void
    {
        Cache::put("ai:turn:{$uuid}:stop", true, self::TTL);
    }

    public static function shouldStop(string $uuid): bool
    {
        return (bool) Cache::get("ai:turn:{$uuid}:stop", false);
    }

    public static function clearStop(string $uuid): void
    {
        Cache::forget("ai:turn:{$uuid}:stop");
    }

    public static function clear(string $uuid): void
    {
        Cache::forget("ai:turn:{$uuid}:seq");
        Cache::forget("ai:turn:{$uuid}:partial");
        Cache::forget("ai:turn:{$uuid}:stop");
    }
}
