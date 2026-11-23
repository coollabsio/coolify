<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Custom theme share code: four hex colors (#rgb or #rrggbb), separated by commas or spaces.
 * The same format as window.parseCustomTheme in layouts/base.blade.php.
 */
class ValidCustomTheme implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normalize($value) === null) {
            $fail('The :attribute must be four hex colors, separated by commas.');
        }
    }

    /**
     * The code as "#rrggbb,#rrggbb,#rrggbb,#rrggbb", or null when the value is not a valid code.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 255) {
            return null;
        }
        $colors = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (count($colors) !== 4) {
            return null;
        }
        $normalized = [];
        foreach ($colors as $color) {
            if (preg_match('/\A#?([a-f\d]{3}|[a-f\d]{6})\z/i', $color, $matches) !== 1) {
                return null;
            }
            $hex = strtolower($matches[1]);
            $normalized[] = '#'.(strlen($hex) === 3 ? preg_replace('/./', '$0$0', $hex) : $hex);
        }

        return implode(',', $normalized);
    }
}
