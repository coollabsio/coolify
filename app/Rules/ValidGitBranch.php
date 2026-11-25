<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class ValidGitBranch implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $branch = trim($value);

        // Check for dangerous shell metacharacters
        $dangerousChars = [
            ';', '|', '&', '$', '`', '(', ')', '{', '}',
            '<', '>', '\n', '\r', '\0', '"', "'", '\\',
            '!', '*', '?', '[', ']', '~', '^', ':', ' ',
            '#',
        ];

        foreach ($dangerousChars as $char) {
            if (str_contains($branch, $char)) {
                Log::warning('Git branch validation failed - dangerous character', [
                    'branch' => $branch,
                    'character' => $char,
                    'ip' => request()->ip(),
                    'user_id' => auth()->id(),
                ]);
                $fail('The :attribute contains invalid characters.');

                return;
            }
        }

        // Git branch name rules:
        // - Cannot contain: .., //, @{
        // - Cannot start or end with: / or .
        // - Cannot be empty after trimming

        if (str_contains($branch, '..') ||
            str_contains($branch, '//') ||
            str_contains($branch, '@{')) {
            $fail('The :attribute contains invalid patterns.');

            return;
        }

        if (str_starts_with($branch, '/') ||
            str_ends_with($branch, '/') ||
            str_starts_with($branch, '.') ||
            str_ends_with($branch, '.')) {
            $fail('The :attribute cannot start or end with / or .');

            return;
        }

        // Allow only safe characters for branch names
        // Letters, numbers, hyphens, underscores, forward slashes, and dots
        if (! preg_match('/^[a-zA-Z0-9\-_\/\.]+$/', $branch)) {
            $fail('The :attribute contains invalid characters. Only letters, numbers, hyphens, underscores, forward slashes, and dots are allowed.');

            return;
        }

        // Additional Git-specific validations
        // Branch name cannot be 'HEAD'
        if ($branch === 'HEAD') {
            $fail('The :attribute cannot be HEAD.');

            return;
        }

        // Check for consecutive dots (not allowed in Git)
        if (str_contains($branch, '..')) {
            $fail('The :attribute cannot contain consecutive dots.');

            return;
        }

        // Check for .lock suffix (reserved by Git)
        if (str_ends_with($branch, '.lock')) {
            $fail('The :attribute cannot end with .lock.');

            return;
        }
    }
}
