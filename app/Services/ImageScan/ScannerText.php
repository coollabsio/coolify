<?php

namespace App\Services\ImageScan;

class ScannerText
{
    /**
     * Strip control and format characters (including ANSI escape starts and bidi overrides) and cut to $max characters.
     */
    public static function clean(mixed $value, int $max): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        return mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value) ?? '', 0, $max);
    }

    /**
     * A short single-line title for a finding: the first sentence of the description, else of the related
     * description, without Markdown, URLs, or invisible characters.
     */
    public static function title(?string $description, ?string $relatedDescription, string $package, string $version, string $severity): string
    {
        $text = self::sentence($description) ?: self::sentence($relatedDescription);

        if (count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 4) {
            return self::fallbackTitle($package, $version, $severity);
        }

        return $text;
    }

    public static function fallbackTitle(string $package, string $version, string $severity): string
    {
        $prefix = trim(preg_replace('/\s+/u', ' ', self::clean(trim($package.' '.$version), 80)) ?? '');
        $label = trim(self::clean($severity, 16));
        $label = $label !== '' ? $label : 'unknown';

        return $prefix !== '' ? "{$prefix}: {$label} vulnerability" : "{$label} vulnerability";
    }

    private static function sentence(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = self::clean($text, 2000);
        $text = preg_replace('/!?\[([^\]]*)\]\([^)\s]*\)/u', '$1', $text) ?? '';
        $text = preg_replace('~\(\s*(?:https?|ftp)://[^\s()]*\s*\)~iu', '', $text) ?? '';
        $text = preg_replace_callback(
            '~(?:https?|ftp)://\S+|\bwww\.\S+~iu',
            fn (array $match): string => substr($match[0], strlen(rtrim($match[0], '.,;:)'))),
            $text,
        ) ?? '';
        $text = preg_replace('/(?<!\w)(\*\*|~~)(?=\S)(.+?)(?<=\S)\1(?!\w)/u', '$2', $text) ?? '';
        $text = preg_replace('/(?<!\w)__(?=\S)([^_]*?\s[^_]*?)(?<=\S)__(?!\w)/u', '$1', $text) ?? '';
        $text = preg_replace('/`(?=\S)([^`]*?)(?<=\S)`/u', '$1', $text) ?? '';
        $text = preg_replace('/^(?:#{1,6}|>)\s+/u', '', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (preg_match('/^(.*?(?i:(?<!\be\.g)(?<!\bi\.e))[.!?])\s+\p{Lu}/su', $text, $match) === 1) {
            $text = $match[1];
        }

        if (mb_strlen($text) <= 120) {
            return $text;
        }

        $cut = mb_substr($text, 0, 119);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t,;:.-").'…';
    }
}
