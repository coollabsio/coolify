<?php

namespace App\Http\Controllers\Webhook\Concerns;

trait DetectsSkipDeployCommits
{
    /**
     * Returns true if there is at least one non-empty message and every message
     * contains [skip cd] or [skip ci] (case-insensitive).
     *
     * Accepts commit messages from a push payload. Null/empty entries are
     * filtered before evaluation.
     *
     * @param  array<int, string|null>  $messages
     */
    public static function shouldSkipDeploy(array $messages): bool
    {
        $messages = array_values(array_filter($messages, fn ($m) => filled($m)));

        if (empty($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            $lower = strtolower((string) $message);
            if (! str_contains($lower, '[skip cd]') && ! str_contains($lower, '[skip ci]')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true if at least one non-empty message contains [skip cd] or
     * [skip ci]. Used for PR/MR title + latest-commit signals where any one
     * marker should trigger the skip.
     *
     * @param  array<int, string|null>  $messages
     */
    public static function shouldSkipDeployAny(array $messages): bool
    {
        foreach ($messages as $message) {
            if (! filled($message)) {
                continue;
            }
            $lower = strtolower((string) $message);
            if (str_contains($lower, '[skip cd]') || str_contains($lower, '[skip ci]')) {
                return true;
            }
        }

        return false;
    }
}
