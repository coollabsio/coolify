<?php

namespace App\Services\ImageScan;

/**
 * An image of a compose file that a variable sets, in one of the two forms that a security update can change through
 * the Coolify environment variable: the whole image (`${N8N_IMAGE}`, `${N8N_IMAGE:-n8nio/n8n:1.0}`) or the tag only
 * (`n8nio/n8n:${N8N_VERSION:-1.0}`). Other forms (two variables, a variable in the name) cannot be changed, but
 * `interpolate()` resolves them for scans.
 */
final readonly class ComposeImageVariable
{
    private const VARIABLE = '\$\{(?<key>[A-Za-z_][A-Za-z0-9_]*)(?:(?<op>:?[-?+])(?<default>[^}]*))?\}|\$(?<bare>[A-Za-z_][A-Za-z0-9_]*)';

    private function __construct(
        public string $key,
        public bool $tagOnly,
        public string $prefix,
        public ?string $default,
    ) {}

    /**
     * Null for an image without a variable, or with a variable in a form that Coolify cannot change.
     */
    public static function parse(string $image): ?self
    {
        $image = trim($image);
        $variable = match (true) {
            preg_match('/\A(?:'.self::VARIABLE.')\z/', $image, $match) === 1 => self::from($match, false, ''),
            preg_match('/\A(?<prefix>[^$:@\s]+(?::[0-9]+)?(?:\/[^$:@\s]+)*):(?:'.self::VARIABLE.')\z/', $image, $match) === 1 => self::from($match, true, $match['prefix']),
            default => null,
        };

        // `${TAG:+value}` sets no value that Coolify can change.
        return $variable !== null && str_ends_with($match['op'] ?? '', '+') ? null : $variable;
    }

    /**
     * The names of the variables in the image.
     *
     * @return list<string>
     */
    public static function keys(string $image): array
    {
        preg_match_all('/'.self::VARIABLE.'/', $image, $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_map(fn (array $match): string => ($match['bare'] ?? '') !== '' ? $match['bare'] : $match['key'], $matches)));
    }

    /**
     * The image that Compose starts with these variable values, with every variable of the image resolved
     * (`${REPO:-ghcr.io/org/app}:${TAG:-1.0}`). An empty value counts as not set, as in `resolve()`. Null when a
     * variable has no value and no default, or requires one (`${TAG:?}`).
     *
     * @param  array<string, ?string>  $values
     */
    public static function interpolate(string $image, array $values): ?string
    {
        $unresolved = false;
        $ref = preg_replace_callback('/\$\$|'.self::VARIABLE.'/', function (array $match) use ($values, &$unresolved): string {
            if ($match[0] === '$$') {
                return '$';
            }

            $key = ($match['bare'] ?? '') !== '' ? $match['bare'] : $match['key'];
            $op = $match['op'] ?? '';
            $value = $values[$key] ?? null;
            $isSet = $value !== null && $value !== '';
            if (str_ends_with($op, '+')) {
                return $isSet ? $match['default'] : '';
            }
            if ($isSet) {
                return $value;
            }
            if (str_ends_with($op, '-')) {
                return $match['default'];
            }
            $unresolved = true;

            return '';
        }, trim($image));

        return $unresolved || $ref === null ? null : $ref;
    }

    /**
     * Whether the image names a variable at all (supported or not).
     */
    public static function hasVariable(string $image): bool
    {
        return str_contains($image, '$');
    }

    /**
     * The image that Compose starts when the variable has this value (null: not set, so the default applies).
     */
    public function resolve(?string $value): ?string
    {
        $value = $value !== null && $value !== '' ? $value : $this->default;
        if ($value === null || $value === '') {
            return null;
        }

        return $this->tagOnly ? "{$this->prefix}:{$value}" : $value;
    }

    /**
     * The value of the variable that starts the image with this tag. `$current` is the image it starts now.
     */
    public function valueFor(string $current, string $tag): string
    {
        if ($this->tagOnly) {
            return $tag;
        }

        $lastColon = strrpos($current, ':');
        $lastSlash = strrpos($current, '/');
        $name = $lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash) ? substr($current, 0, $lastColon) : $current;

        return "{$name}:{$tag}";
    }

    /**
     * @param  array<string, string>  $match
     */
    private static function from(array $match, bool $tagOnly, string $prefix): self
    {
        $key = ($match['bare'] ?? '') !== '' ? $match['bare'] : $match['key'];
        $default = ($match['op'] ?? '') !== '' && str_ends_with($match['op'], '-') ? $match['default'] : null;

        return new self($key, $tagOnly, $prefix, $default);
    }
}
