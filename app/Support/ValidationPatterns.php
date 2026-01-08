<?php

namespace App\Support;

/**
 * Shared validation patterns for consistent use across the application
 */
class ValidationPatterns
{
    /**
     * Pattern for names excluding all dangerous characters
    */
    public const NAME_PATTERN = '/^[\p{L}\p{M}\p{N}\s\-_.]+$/u';

    /**
     * Pattern for descriptions excluding all dangerous characters with some additional allowed characters
     */
    public const DESCRIPTION_PATTERN = '/^[\p{L}\p{M}\p{N}\s\-_.,!?()\'\"+=*]+$/u';

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
            'name.regex' => "The name may only contain letters (including Unicode), numbers, spaces, dashes (-), underscores (_) and dots (.).",
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
            'description.regex' => "The description may only contain letters (including Unicode), numbers, spaces, and common punctuation (- _ . , ! ? ( ) ' \" + = *).",
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
