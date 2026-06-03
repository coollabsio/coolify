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
    public const NAME_PATTERN = '/^[\p{L}\p{M}\p{N}\s\-_.@\/&()#,:+]+$/u';

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
     * Pattern for SSH usernames.
     * Allows alphanumeric characters, dots, hyphens, and underscores.
     */
    public const SERVER_USERNAME_PATTERN = '/^[a-zA-Z0-9._-]+$/';

    /**
     * Pattern for removing characters not allowed in SSH usernames.
     */
    public const INVALID_SERVER_USERNAME_CHARACTERS_PATTERN = '/[^A-Za-z0-9.\-_]/';

    /**
     * Token-aware pattern for shell-safe command strings (docker compose commands, docker run options).
     *
     * Accepts a sequence of the following tokens only:
     *   [ \t]+          — whitespace (space / tab)
     *   &&              — logical AND (matched before bare & can match anything)
     *   ||              — logical OR  (matched before bare | can match anything)
     *   "[^"$`\\\n\r]*" — balanced double-quoted string; blocks $, backtick, \, newlines inside
     *   '[^'\n\r]*'     — balanced single-quoted string; blocks newlines inside (all else literal)
     *   [safe-chars]+   — unquoted alphanumerics + safe path/arg chars (includes glob *, ?, and !)
     *
     * Blocked everywhere (outside and inside unquoted tokens):
     *   bare & (background op), bare |, ;, $, `, (, ), <, >, \, newline, CR
     *
     * Blocked inside double-quoted spans specifically:
     *   $ (variable/command expansion), ` (command substitution), \ (escape)
     *
     * Legitimate use cases preserved:
     *   docker compose build && docker tag x && docker push y
     *   make build || make clean
     *   rm *.tmp      cp src/?.js dist/
     *   ! grep -q foo && echo missing
     *   docker compose up -d --build-arg VERSION="1.0.0"
     *   --entrypoint "sh -c 'npm start'"
     */
    public const SHELL_SAFE_COMMAND_PATTERN = '/^(?:[ \t]+|&&|\|\||"[^"$`\\\\\n\r]*"|\'[^\'\n\r]*\'|[a-zA-Z0-9._\-\/=:@,+\[\]{}#%^~*?!]+)+$/';

    /**
     * Pattern for Docker volume names
     * Must start with alphanumeric, followed by alphanumeric, dots, hyphens, or underscores
     * Matches Docker's volume naming rules
     */
    public const VOLUME_NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';

    /**
     * Pattern for Docker container names
     * Must start with alphanumeric, followed by alphanumeric, dots, hyphens, or underscores
     */
    public const CONTAINER_NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';

    /**
     * Pattern for Docker network names
     * Must start with alphanumeric, followed by alphanumeric, dots, hyphens, or underscores
     * Matches Docker's network naming rules and prevents shell injection
     */
    public const DOCKER_NETWORK_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';

    /**
     * Pattern for Docker-compatible environment variable keys.
     * Docker environment entries are KEY=value strings, so keys must be non-empty and cannot contain '=' or NUL.
     */
    public const ENVIRONMENT_VARIABLE_KEY_PATTERN = '/\A[^=\x00]+\z/u';

    /**
     * Pattern for SQL-safe unquoted database identifiers (usernames, database names).
     * Allows letters, digits, underscore; first char must be letter or underscore.
     * Excludes all shell metacharacters. Max 63 chars (Postgres identifier limit).
     */
    public const DB_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/';

    /**
     * Pattern for database passwords.
     * Excludes shell-dangerous characters: backtick, $, ;, |, &, <, >, \, ', ", space, newline, CR, tab, null.
     * Allows a broad set of printable characters so passwords remain strong.
     */
    public const DB_PASSWORD_PATTERN = '/^[A-Za-z0-9!@#%^*()_+\-=\[\]{}:,.?\/~]+$/';

    /**
     * Pattern for Docker image repository names without a tag.
     *
     * Allows an optional registry host/port followed by lowercase repository
     * path components. A trailing @sha256 marker is accepted for existing
     * digest-based dockerimage records that store the digest hash separately.
     */
    public const DOCKER_IMAGE_NAME_PATTERN = '/\A(?=.{1,255}\z)(?:(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]+)?\/)?[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*)(?:@sha256)?\z/';

    /**
     * Pattern for Docker image tags.
     *
     * Docker tags may contain letters, digits, underscores, dots, and hyphens,
     * must start with an alphanumeric/underscore, and are limited to 128 chars.
     */
    public const DOCKER_IMAGE_TAG_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/';

    /**
     * Normalize environment variable keys before validation and storage.
     */
    public static function normalizeEnvironmentVariableKey(string $value): string
    {
        return str($value)->trim()->value;
    }

    /**
     * Get validation rules for environment variable keys.
     */
    public static function environmentVariableKeyRules(bool $required = true, int $maxLength = 255): array
    {
        $rules = [];

        if ($required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        $rules[] = 'string';
        $rules[] = "max:$maxLength";
        $rules[] = 'regex:'.self::ENVIRONMENT_VARIABLE_KEY_PATTERN;

        return $rules;
    }

    /**
     * Get validation messages for environment variable key fields.
     */
    public static function environmentVariableKeyMessages(string $field = 'key', string $label = 'key'): array
    {
        return [
            "{$field}.regex" => "The {$label} must be a non-empty Docker-compatible environment variable key and cannot contain '=' or NUL characters.",
            "{$field}.max" => "The {$label} may not be greater than :max characters.",
        ];
    }

    /**
     * Check if a string is a valid environment variable key.
     */
    public static function isValidEnvironmentVariableKey(string $value): bool
    {
        return preg_match(self::ENVIRONMENT_VARIABLE_KEY_PATTERN, $value) === 1;
    }

    /**
     * Normalize and validate an environment variable key.
     */
    public static function validatedEnvironmentVariableKey(string $value, string $label = 'key'): string
    {
        $key = self::normalizeEnvironmentVariableKey($value);

        if (! self::isValidEnvironmentVariableKey($key)) {
            throw new \InvalidArgumentException(self::environmentVariableKeyMessages(label: $label)['key.regex']);
        }

        return $key;
    }

    /**
     * Get validation rules for Docker image repository names without tags.
     */
    public static function dockerImageNameRules(bool $required = false, int $maxLength = 255): array
    {
        $rules = [];

        if ($required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        $rules[] = 'string';
        $rules[] = "max:$maxLength";
        $rules[] = 'regex:'.self::DOCKER_IMAGE_NAME_PATTERN;

        return $rules;
    }

    /**
     * Get validation rules for Docker image tags.
     */
    public static function dockerImageTagRules(bool $required = false, int $maxLength = 128): array
    {
        $rules = [];

        if ($required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        $rules[] = 'string';
        $rules[] = "max:$maxLength";
        $rules[] = 'regex:'.self::DOCKER_IMAGE_TAG_PATTERN;

        return $rules;
    }

    /**
     * Get validation messages for Docker image fields.
     */
    public static function dockerImageMessages(string $nameField = 'docker_registry_image_name', string $tagField = 'docker_registry_image_tag'): array
    {
        return [
            "{$nameField}.regex" => 'The Docker registry image name must be a valid image repository without a tag and may not contain shell metacharacters.',
            "{$tagField}.regex" => 'The Docker registry image tag must be a valid Docker tag and may not contain shell metacharacters.',
        ];
    }

    /**
     * Check if a string is a valid Docker image repository name without a tag.
     */
    public static function isValidDockerImageName(?string $value): bool
    {
        if (blank($value)) {
            return true;
        }

        return preg_match(self::DOCKER_IMAGE_NAME_PATTERN, $value) === 1;
    }

    /**
     * Check if a string is a valid Docker image tag.
     */
    public static function isValidDockerImageTag(?string $value): bool
    {
        if (blank($value)) {
            return true;
        }

        return preg_match(self::DOCKER_IMAGE_TAG_PATTERN, $value) === 1;
    }

    /**
     * Get validation rules for database identifier fields (username, database name).
     *
     * Set $enforcePattern to false to skip the regex check (for example when
     * re-validating a legacy value on an existing record that has not been
     * changed by the user). The length and type rules are always applied.
     */
    public static function databaseIdentifierRules(bool $required = true, int $minLength = 1, int $maxLength = 63, bool $enforcePattern = true): array
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

        if ($enforcePattern) {
            $rules[] = 'regex:'.self::DB_IDENTIFIER_PATTERN;
        }

        return $rules;
    }

    /**
     * Get validation rules for SSH username fields.
     */
    public static function serverUsernameRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'regex:'.self::SERVER_USERNAME_PATTERN,
        ];
    }

    /**
     * Get validation messages for SSH username fields.
     */
    public static function serverUsernameMessages(string $field = 'user', string $label = 'User'): array
    {
        return [
            "{$field}.regex" => "The {$label} may only contain letters, numbers, dots, hyphens, and underscores.",
        ];
    }

    /**
     * Get validation messages for database identifier fields.
     */
    public static function databaseIdentifierMessages(string $field, string $label = ''): array
    {
        $label = $label ?: $field;

        return [
            "{$field}.regex" => "The {$label} may only contain letters, digits, and underscores, and must start with a letter or underscore.",
            "{$field}.min" => "The {$label} must be at least :min character.",
            "{$field}.max" => "The {$label} may not be greater than :max characters.",
        ];
    }

    /**
     * Get validation rules for database password fields.
     *
     * Set $enforcePattern to false to skip the regex check (for example when
     * re-validating a legacy value on an existing record that has not been
     * changed by the user). The length and type rules are always applied.
     */
    public static function databasePasswordRules(bool $required = true, int $minLength = 1, int $maxLength = 128, bool $enforcePattern = true): array
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

        if ($enforcePattern) {
            $rules[] = 'regex:'.self::DB_PASSWORD_PATTERN;
        }

        return $rules;
    }

    /**
     * Get validation messages for database password fields.
     */
    public static function databasePasswordMessages(string $field, string $label = ''): array
    {
        $label = $label ?: $field;

        return [
            "{$field}.regex" => "The {$label} may not contain shell-unsafe characters (backtick, \$, ;, |, &, <, >, \\, quotes, spaces, or control characters).",
            "{$field}.min" => "The {$label} must be at least :min character.",
            "{$field}.max" => "The {$label} may not be greater than :max characters.",
        ];
    }

    /**
     * Check if a string is a valid database identifier.
     */
    public static function isValidDatabaseIdentifier(string $value): bool
    {
        return preg_match(self::DB_IDENTIFIER_PATTERN, $value) === 1;
    }

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
            'name.regex' => 'The name may only contain letters (including Unicode), numbers, spaces, and these characters: - _ . / @ & ( ) # , : +',
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
     * Get validation rules for Docker volume name fields
     */
    public static function volumeNameRules(bool $required = true, int $maxLength = 255): array
    {
        $rules = [];

        if ($required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        $rules[] = 'string';
        $rules[] = "max:$maxLength";
        $rules[] = 'regex:'.self::VOLUME_NAME_PATTERN;

        return $rules;
    }

    /**
     * Get validation messages for volume name fields
     */
    public static function volumeNameMessages(string $field = 'name'): array
    {
        return [
            "{$field}.regex" => 'The volume name must start with an alphanumeric character and contain only alphanumeric characters, dots, hyphens, and underscores.',
        ];
    }

    /**
     * Pattern for port mappings with optional IP binding and protocol suffix on either side.
     * Format: [ip:]port[:ip:port] where IP is IPv4 or [IPv6], port can be a range, protocol suffix optional.
     * Examples: 8080:80, 127.0.0.1:8080:80, [::1]::80/udp, 127.0.0.1:8080:80/tcp
     */
    public const PORT_MAPPINGS_PATTERN = '/^
        (?:(?:\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}|\[[\da-fA-F:]+\]):)?  # optional IP
        (?:\d+(?:-\d+)?(?:\/(?:tcp|udp|sctp))?)?                         # optional host port
        :
        (?:(?:\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}|\[[\da-fA-F:]+\]):)?  # optional IP
        \d+(?:-\d+)?(?:\/(?:tcp|udp|sctp))?                              # container port
        (?:,
            (?:(?:\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}|\[[\da-fA-F:]+\]):)?
            (?:\d+(?:-\d+)?(?:\/(?:tcp|udp|sctp))?)?
            :
            (?:(?:\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}|\[[\da-fA-F:]+\]):)?
            \d+(?:-\d+)?(?:\/(?:tcp|udp|sctp))?
        )*
    $/x';

    /**
     * Get validation rules for container name fields
     */
    public static function containerNameRules(int $maxLength = 255): array
    {
        return ['string', 'max:'.$maxLength, 'regex:'.self::CONTAINER_NAME_PATTERN];
    }

    /**
     * Get validation rules for port mapping fields
     */
    public static function portMappingRules(): array
    {
        return ['nullable', 'string', 'regex:'.self::PORT_MAPPINGS_PATTERN];
    }

    /**
     * Get validation messages for port mapping fields
     */
    public static function portMappingMessages(string $field = 'portsMappings'): array
    {
        return [
            "{$field}.regex" => 'Port mappings must be a comma-separated list of port pairs or ranges with optional IP and protocol (e.g. 3000:3000, 8080:80/udp, 127.0.0.1:8080:80, [::1]::80).',
        ];
    }

    /**
     * Check if a string is a valid Docker container name.
     */
    public static function isValidContainerName(string $name): bool
    {
        return preg_match(self::CONTAINER_NAME_PATTERN, $name) === 1;
    }

    /**
     * Get validation rules for Docker network name fields
     */
    public static function dockerNetworkRules(bool $required = true, int $maxLength = 255): array
    {
        $rules = [];

        if ($required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        $rules[] = 'string';
        $rules[] = "max:$maxLength";
        $rules[] = 'regex:'.self::DOCKER_NETWORK_PATTERN;

        return $rules;
    }

    /**
     * Get validation messages for Docker network name fields
     */
    public static function dockerNetworkMessages(string $field = 'network'): array
    {
        return [
            "{$field}.regex" => 'The network name must start with an alphanumeric character and contain only alphanumeric characters, dots, hyphens, and underscores.',
        ];
    }

    /**
     * Check if a string is a valid Docker network name.
     */
    public static function isValidDockerNetwork(string $name): bool
    {
        return preg_match(self::DOCKER_NETWORK_PATTERN, $name) === 1;
    }

    /**
     * Get combined validation messages for both name and description fields
     */
    public static function combinedMessages(): array
    {
        return array_merge(self::nameMessages(), self::descriptionMessages());
    }
}
