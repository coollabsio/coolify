<?php

namespace App\Services\ImageScan;

/**
 * An image of a compose file that a variable sets, in one of the two forms that a security update can change through
 * the Coolify environment variable: the whole image (`${N8N_IMAGE}`, `${N8N_IMAGE:-n8nio/n8n:1.0}`) or the tag only
 * (`n8nio/n8n:${N8N_VERSION:-1.0}`). Other forms (two variables, a variable in the name) are not supported.
 */
final readonly class ComposeImageVariable
{
    private const VARIABLE = '\$\{(?<key>[A-Za-z_][A-Za-z0-9_]*)(?:(?<op>:?[-?])(?<default>[^}]*))?\}|\$(?<bare>[A-Za-z_][A-Za-z0-9_]*)';

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
        if (preg_match('/\A(?:'.self::VARIABLE.')\z/', $image, $match) === 1) {
            return self::from($match, false, '');
        }
        if (preg_match('/\A(?<prefix>[^$:@\s]+(?::[0-9]+)?(?:\/[^$:@\s]+)*):(?:'.self::VARIABLE.')\z/', $image, $match) === 1) {
            return self::from($match, true, $match['prefix']);
        }

        return null;
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
