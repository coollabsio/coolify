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
    public const NAME_PATTERN = '/^[\p{L}\p{M}\p{N}\s\-_.@\/&]+$/u';

    /**
     * Pattern for descriptions excluding all dangerous characters with some additional allowed characters
     */
    public const DESCRIPTION_PATTERN = '/^[\p{L}\p{M}\p{N}\s\-_.,!?()\'\"+=*@\/&]+$/u';

    /**
     * Pattern for file paths (dockerfile location, docker compose location, etc.)
     * Allows alphanumeric, dots, hyphens, underscores, slashes, @, ~, and +
     */
    public const FILE_PATH_PATTERN = '/^\/[a-zA-Z0-9._\-\/~@+]+$/';

    /**
     * Pattern for directory paths (base_directory, publish_directory, etc.)
     * Like FILE_PATH_PATTERN but also allows bare "/" (root directory)
     */
    public const DIRECTORY_PATH_PATTERN = '/^\/([a-zA-Z0-9._\-\/~@+]*)?$/';

    /**
     * Pattern for Docker build target names (multi-stage build stage names)
     * Allows alphanumeric, dots, hyphens, and underscores
     */
    public const DOCKER_TARGET_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';

    /**
     * Pattern for shell-safe command strings (docker compose commands, docker run options)
     * Blocks dangerous shell metacharacters: ; & | ` $ ( ) > < newlines and carriage returns
     * Also blocks backslashes, single quotes, and double quotes to prevent escape-sequence attacks
     * Uses [ \t] instead of \s to explicitly exclude \n and \r (which act as command separators)
     */
    public const SHELL_SAFE_COMMAND_PATTERN = '/^[a-zA-Z0-9 \t._\-\/=:@,+\[\]{}#%^~]+$/';

    /**
     * Pattern for Docker container names
     * Must start with alphanumeric, followed by alphanumeric, dots, hyphens, or underscores
     */
    public const CONTAINER_NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';

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
            'name.regex' => 'The name may only contain letters (including Unicode), numbers, spaces, and these characters: - _ . / @ &',
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
            'description.regex' => "The description may only contain letters (including Unicode), numbers, spaces, and common punctuation: - _ . , ! ? ( ) ' \" + = * / @ &",
            'description.max' => 'The description may not be greater than :max characters.',
        ];
    }

    /**
     * Get validation rules for file path fields (dockerfile location, docker compose location)
     */
    public static function filePathRules(int $maxLength = 255): array
    {
        return ['nullable', 'string', 'max:'.$maxLength, 'regex:'.self::FILE_PATH_PATTERN];
    }

    /**
     * Get validation messages for file path fields
     */
    public static function filePathMessages(string $field = 'dockerfileLocation', string $label = 'Dockerfile'): array
    {
        return [
            "{$field}.regex" => "The {$label} location must be a valid path starting with / and containing only alphanumeric characters, dots, hyphens, underscores, slashes, @, ~, and +.",
        ];
    }

    /**
     * Get validation rules for directory path fields (base_directory, publish_directory)
     */
    public static function directoryPathRules(int $maxLength = 255): array
    {
        return ['nullable', 'string', 'max:'.$maxLength, 'regex:'.self::DIRECTORY_PATH_PATTERN];
    }

    /**
     * Get validation rules for Docker build target fields
     */
    public static function dockerTargetRules(int $maxLength = 128): array
    {
        return ['nullable', 'string', 'max:'.$maxLength, 'regex:'.self::DOCKER_TARGET_PATTERN];
    }

    /**
     * Get validation rules for shell-safe command fields
     */
    public static function shellSafeCommandRules(int $maxLength = 1000): array
    {
        return ['nullable', 'string', 'max:'.$maxLength, 'regex:'.self::SHELL_SAFE_COMMAND_PATTERN];
    }

    /**
     * Get validation rules for container name fields
     */
    public static function containerNameRules(int $maxLength = 255): array
    {
        return ['string', 'max:'.$maxLength, 'regex:'.self::CONTAINER_NAME_PATTERN];
    }

    /**
     * Get combined validation messages for both name and description fields
     */
    public static function combinedMessages(): array
    {
        return array_merge(self::nameMessages(), self::descriptionMessages());
    }
}
