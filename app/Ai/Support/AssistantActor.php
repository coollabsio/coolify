<?php

namespace App\Ai\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Context;

/**
 * Marks the user that the in-app assistant acts for during a queued turn. The reused
 * MCP read tools accept a tokenless user only when this hidden marker matches, so an
 * HTTP request without a token never gets the assistant's session-based access.
 */
class AssistantActor
{
    private const CONTEXT_KEY = 'ai.acting_user_id';

    public static function set(int $userId): void
    {
        Context::addHidden(self::CONTEXT_KEY, $userId);
    }

    public static function clear(): void
    {
        Context::forgetHidden(self::CONTEXT_KEY);
    }

    public static function is(?Authenticatable $user): bool
    {
        return $user !== null && Context::getHidden(self::CONTEXT_KEY) === $user->getAuthIdentifier();
    }
}
