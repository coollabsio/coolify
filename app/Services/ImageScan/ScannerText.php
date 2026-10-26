<?php

namespace App\Services\ImageScan;

class ScannerText
{
    /**
     * Strip control characters (including ANSI escape starts) and cut to $max characters.
     */
    public static function clean(mixed $value, int $max): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        return mb_substr(preg_replace('/\p{Cc}/u', '', $value) ?? '', 0, $max);
    }
}
