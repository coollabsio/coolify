<?php

namespace App\Services\ProjectConfig;

/**
 * Converts stored column values to file values and back.
 */
final class FieldValues
{
    public const BASE64 = 'base64';

    public const LINES = 'lines';

    public const JSON_TEXT = 'json_text';

    public static function toFile(Field $field, mixed $stored): mixed
    {
        if ($stored === null) {
            return null;
        }
        if ($stored instanceof \BackedEnum) {
            $stored = $stored->value;
        }
        if ($stored instanceof \Stringable) {
            $stored = (string) $stored;
        }

        $value = match ($field->format) {
            self::BASE64 => base64_decode((string) $stored, true) === false ? (string) $stored : base64_decode((string) $stored, true),
            self::LINES => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $stored)), fn ($line) => $line !== '')),
            self::JSON_TEXT => is_string($stored) ? json_decode($stored, true) : $stored,
            default => $stored,
        };

        return self::cast($field->jsonType, $value);
    }

    public static function toStored(Field $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field->format) {
            self::BASE64 => base64_encode((string) $value),
            self::LINES => implode("\n", (array) $value),
            self::JSON_TEXT => json_encode($value),
            default => $value,
        };
    }

    public static function cast(string $jsonType, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        return match ($jsonType) {
            'boolean' => (bool) $value,
            'integer' => is_numeric($value) ? (int) $value : $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }

    /**
     * Lowercase scheme and host, no trailing slash. Paths keep their case.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $parts = parse_url($domain);
        if ($parts === false || ! isset($parts['host'])) {
            return rtrim($domain, '/');
        }

        $result = strtolower($parts['scheme'] ?? 'http').'://'.strtolower($parts['host']);
        if (isset($parts['port'])) {
            $result .= ':'.$parts['port'];
        }
        $result .= rtrim($parts['path'] ?? '', '/');
        if (isset($parts['query'])) {
            $result .= '?'.$parts['query'];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function domainList(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        return array_values(array_map(self::normalizeDomain(...), array_filter(array_map('trim', explode(',', $stored)), fn ($d) => $d !== '')));
    }
}
