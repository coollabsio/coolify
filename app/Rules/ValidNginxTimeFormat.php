<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidNginxTimeFormat implements ValidationRule
{
    
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Allow null/empty values as they can be handled as nullable
        if (empty($value) && $value !== '0') {
            return;
        }

        // Pattern to match nginx time format:
        // - 0 for unlimited
        // - Plain positive integers (seconds): 1, 30, 3600
        // - Time with suffix: 30s, 5m, 2h, 7d
        $pattern = '/^(0|[1-9]\d*|[1-9]\d*[smhd])$/';

        if (! preg_match($pattern, $value)) {
            $fail("The {$attribute} must be a valid nginx time format (e.g., '30', '30s', '5m', '2h', '7d', or '0' for unlimited).");
        }
    }

    /**
     * Convert nginx time format to seconds
     */
    public static function convertToSeconds(string $value): int
    {
        if ($value === '0' || $value === 0) {
            return 0;
        }

        // If it's just a number, treat it as seconds
        if (is_numeric($value)) {
            return (int) $value;
        }

        // Extract number and suffix
        preg_match('/^(\d+)([smhd])$/', $value, $matches);

        if (empty($matches)) {
            return 0;
        }

        $number = (int) $matches[1];
        $suffix = $matches[2];

        return match ($suffix) {
            's' => $number,
            'm' => $number * 60,
            'h' => $number * 60 * 60,
            'd' => $number * 60 * 60 * 24,
            default => 0,
        };
    }

    /**
     * Normalize value to nginx time format string
     */
    public static function normalizeFormat(string|int|null $value): string
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return '0';
        }

        // If it's already in a valid format with suffix, return as is
        if (is_string($value) && preg_match('/^\d+[smhd]$/', $value)) {
            return $value;
        }

        // Convert plain numbers to seconds format
        if (is_numeric($value)) {
            return $value.'s';
        }

        return '0';
    }
}
