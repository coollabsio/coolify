<?php

namespace App\Http\Controllers\Webhook\Concerns;

trait DetectsSkipDeployCommits
{
    /**
     * Returns true if there is at least one non-empty message and every message
     * contains [skip cd] or [skip ci] (case-insensitive).
     *
     * Accepts commit messages from a push payload. Null/empty entries and
     * values that are not strings are filtered before evaluation.
     *
     * @param  array<int, mixed>  $messages
     */
    public static function shouldSkipDeploy(array $messages): bool
    {
        $messages = array_values(array_filter($messages, fn ($m) => is_string($m) && filled($m)));

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
     * marker should trigger the skip. Values that are not strings are ignored.
     *
     * @param  array<int, mixed>  $messages
     */
    public static function shouldSkipDeployAny(array $messages): bool
    {
        foreach ($messages as $message) {
            if (! is_string($message) || ! filled($message)) {
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
