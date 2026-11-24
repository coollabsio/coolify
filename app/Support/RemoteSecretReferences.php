<?php

namespace App\Support;

/**
 * Parses {{vault.KEY}} and {{vault.<source>.KEY}} style references to remote secret manager values.
 * {{vault.KEY}} resolves against the `default` source of the resource, {{vault.<source>.KEY}}
 * against the named source. Keys never contain dots, so both forms parse without ambiguity.
 * The namespace is provider-neutral and intentionally NOT "secret" so references stay visually
 * distinct from the shared variable syntax ({{team.KEY}}, {{project.KEY}}, ...). References are
 * only resolved inside the deployment job — never in the UI or in realValue — so secret values
 * stay out of the database and the interface.
 *
 * Secret maps passed to substitute() and missingKeys() are keyed by reference label: `KEY` for
 * the default source and `<source>.KEY` for a named source (see label() and labelSecrets()).
 */
class RemoteSecretReferences
{
    public const PATTERN = '/{{\s*vault\.(?:([a-z0-9][a-z0-9_-]{0,62})\.)?([A-Za-z0-9_]+)\s*}}/';

    public const DEFAULT_SOURCE = 'default';

    public const SOURCE_NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/';

    public static function isValidSourceName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::SOURCE_NAME_PATTERN, $name) === 1;
    }

    public static function containsReference(?string $value): bool
    {
        return filled($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The reference text for a key of a source: `{{vault.KEY}}` for the default source.
     */
    public static function reference(string $source, string $key): string
    {
        return '{{vault.'.self::label($source, $key).'}}';
    }

    /**
     * The label of a key in a labelled secrets map: `KEY` for the default source, `<source>.KEY` otherwise.
     */
    public static function label(string $source, string $key): string
    {
        return $source === self::DEFAULT_SOURCE ? $key : $source.'.'.$key;
    }

    /**
     * @param  array<string, string>  $secrets  Secrets of one source, keyed by remote key
     * @return array<string, string> The same secrets keyed by label
     */
    public static function labelSecrets(string $source, array $secrets): array
    {
        $labelled = [];
        foreach ($secrets as $key => $secretValue) {
            $labelled[self::label($source, (string) $key)] = $secretValue;
        }

        return $labelled;
    }

    /**
     * @return list<array{source: string, key: string}> Referenced secrets (unique, in order of appearance)
     */
    public static function references(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        preg_match_all(self::PATTERN, $value, $matches, PREG_SET_ORDER);

        $references = [];
        foreach ($matches as $match) {
            $source = $match[1] !== '' ? $match[1] : self::DEFAULT_SOURCE;
            $references[self::label($source, $match[2])] = ['source' => $source, 'key' => $match[2]];
        }

        return array_values($references);
    }

    /**
     * @return list<string> Referenced source names (unique, in order of appearance)
     */
    public static function referencedSources(?string $value): array
    {
        return array_values(array_unique(array_column(self::references($value), 'source')));
    }

    /**
     * @return list<string> Referenced secret labels (unique, in order of appearance): `KEY` or `<source>.KEY`
     */
    public static function referencedKeys(?string $value): array
    {
        return array_map(fn (array $reference): string => self::label($reference['source'], $reference['key']), self::references($value));
    }

    /**
     * Replace every reference with its value from the labelled secrets map.
     * Labels missing from the map are left as-is — collect them first with
     * missingKeys() and fail before calling substitute().
     *
     * @param  array<string, string>  $secrets
     */
    public static function substitute(string $value, array $secrets): string
    {
        return preg_replace_callback(
            self::PATTERN,
            function (array $matches) use ($secrets): string {
                $label = self::label($matches[1] !== '' ? $matches[1] : self::DEFAULT_SOURCE, $matches[2]);

                return array_key_exists($label, $secrets) ? $secrets[$label] : $matches[0];
            },
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
            fn (string $label) => ! array_key_exists($label, $secrets),
        ));
    }
}
