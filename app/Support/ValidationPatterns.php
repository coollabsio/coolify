<?php

namespace App\Support;

/**
 * Shared validation patterns for consistent use across the application
 */
class ValidationPatterns
{
    /**
     * Pattern for names (allows letters, numbers, spaces, dashes, underscores, dots, slashes, colons, parentheses)
     * Matches CleanupNames::sanitizeName() allowed characters
     */
    public const NAME_PATTERN = '/^[a-zA-Z0-9\s\-_.:\/()]+$/';

    /**
     * Pattern for descriptions (allows more characters including quotes, commas, etc.)
     * only rejects control characters (U+0000-U+001F), <, >, ;, $, \
     */
    public const DESCRIPTION_PATTERN = '/^[^\x00-\x1F<>;\\$]+$/';

    /**
     * Get validation rules for name fields
     */
    public static function nameRules(bool $required = true, int $minLength = 3, int $maxLength = 255): array
    {
        $rules = [];

        if ($required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        $rules[] = 'string';
        $rules[] = "min:$minLength";
        $rules[] = "max:$maxLength";
        $rules[] = 'regex:'.self::NAME_PATTERN;

        return $rules;
    }

    /**
     * Get validation rules for description fields
     */
    public static function descriptionRules(bool $required = false, int $maxLength = 255): array
    {
        $rules = [];

        if ($required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        $rules[] = 'string';
        $rules[] = "max:$maxLength";
        $rules[] = 'regex:'.self::DESCRIPTION_PATTERN;

        return $rules;
    }

    /**
     * Get validation messages for name fields
     */
    public static function nameMessages(): array
    {
        return [
            'name.regex' => 'The name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
            'name.min' => 'The name must be at least :min characters.',
            'name.max' => 'The name may not be greater than :max characters.',
        ];
    }

    /**
     * Get validation messages for description fields
     */
    public static function descriptionMessages(): array
    {
        return [
            'description.regex' => 'The description contains invalid characters. Control characters and < > ; $ \ cannot be used.',
            'description.max' => 'The description may not be greater than :max characters.',
        ];
    }

    /**
     * Get combined validation messages for both name and description fields
     */
    public static function combinedMessages(): array
    {
        return array_merge(self::nameMessages(), self::descriptionMessages());
    }
}
