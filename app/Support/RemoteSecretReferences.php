<?php

namespace App\Support;

/**
 * Parses {{vault.KEY}} style references to remote secret manager values.
 * The namespace is provider-neutral and resolves against the resource's
 * configured secret source. It is intentionally
 * intentionally NOT "secret" so references stay visually distinct from the
 * shared variable syntax ({{team.KEY}}, {{project.KEY}}, ...). References are
 * only resolved inside the deployment job — never in the UI or in realValue —
 * so secret values stay out of the database and the interface.
 */
class RemoteSecretReferences
{
    public const PATTERN = '/{{\s*vault\.([A-Za-z0-9_]+)\s*}}/';

    public static function containsReference(?string $value): bool
    {
        return filled($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * @return list<string> Referenced secret key names (unique, in order of appearance)
     */
    public static function referencedKeys(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        preg_match_all(self::PATTERN, $value, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Replace every reference with its value from the secrets map.
     * Keys missing from the map are left as-is — collect them first with
     * missingKeys() and fail before calling substitute().
     *
     * @param  array<string, string>  $secrets
     */
    public static function substitute(string $value, array $secrets): string
    {
        return preg_replace_callback(
            self::PATTERN,
            fn (array $matches) => array_key_exists($matches[1], $secrets) ? $secrets[$matches[1]] : $matches[0],
            $value,
        );
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<string>
     */
    public static function missingKeys(?string $value, array $secrets): array
    {
        return array_values(array_filter(
            self::referencedKeys($value),
            fn (string $key) => ! array_key_exists($key, $secrets),
        ));
    }
}
