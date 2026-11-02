<?php

namespace App\Mcp\Concerns;

/**
 * Hides tools and actions that the current token cannot use, so read-only
 * connections never see write tools and agents do not try calls that must fail.
 */
trait ChecksTokenAbilities
{
    protected function currentTokenCan(string $ability): bool
    {
        $token = request()->user()?->currentAccessToken();

        return $token !== null && ($token->can('root') || $token->can($ability));
    }
}
