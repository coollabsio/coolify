<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class ValidHostname implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Validates hostname according to RFC 1123:
     * - Must be 1-253 characters total
     * - Each label (segment between dots) must be 1-63 characters
     * - Labels can contain lowercase letters (a-z), digits (0-9), and hyphens (-)
     * - Labels cannot start or end with a hyphen
     * - Labels cannot be all numeric
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $hostname = trim($value);

        // Check total length (RFC 1123: max 253 characters)
        if (strlen($hostname) > 253) {
            $fail('The :attribute must not exceed 253 characters.');

            return;
        }

        // Check for dangerous shell metacharacters
        $dangerousChars = [
            ';', '|', '&', '$', '`', '(', ')', '{', '}',
            '<', '>', '\n', '\r', '\0', '"', "'", '\\',
            '!', '*', '?', '[', ']', '~', '^', ':', '#',
            '@', '%', '=', '+', ',', ' ',
        ];

        foreach ($dangerousChars as $char) {
            if (str_contains($hostname, $char)) {
                try {
                    $logData = [
                        'hostname' => $hostname,
                        'character' => $char,
                    ];

                    if (function_exists('request') && app()->has('request')) {
                        $logData['ip'] = request()->ip();
                    }

                    if (function_exists('auth') && app()->has('auth')) {
                        $logData['user_id'] = auth()->id();
                    }

                    Log::warning('Hostname validation failed - dangerous character', $logData);
                } catch (\Throwable $e) {
                    // Ignore errors when facades are not available (e.g., in unit tests)
                }

                $fail('The :attribute contains invalid characters. Only lowercase letters (a-z), numbers (0-9), hyphens (-), and dots (.) are allowed.');

                return;
            }
        }

        // Additional validation: hostname should not start or end with a dot
        if (str_starts_with($hostname, '.') || str_ends_with($hostname, '.')) {
            $fail('The :attribute cannot start or end with a dot.');

            return;
        }

        // Check for consecutive dots
        if (str_contains($hostname, '..')) {
            $fail('The :attribute cannot contain consecutive dots.');

            return;
        }

        // Split into labels (segments between dots)
        $labels = explode('.', $hostname);

        foreach ($labels as $label) {
            // Check label length (RFC 1123: max 63 characters per label)
            if (strlen($label) < 1 || strlen($label) > 63) {
                $fail('The :attribute contains an invalid label. Each segment must be 1-63 characters.');

                return;
            }

            // Check if label starts or ends with hyphen
            if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
                $fail('The :attribute contains an invalid label. Labels cannot start or end with a hyphen.');

                return;
            }

            // Check if label contains only valid characters (lowercase letters, digits, hyphens)
            if (! preg_match('/^[a-z0-9-]+$/', $label)) {
                $fail('The :attribute contains invalid characters. Only lowercase letters (a-z), numbers (0-9), hyphens (-), and dots (.) are allowed.');

                return;
            }

            // RFC 1123 allows labels to be all numeric (unlike RFC 952)
            // So we don't need to check for all-numeric labels
        }
    }
}
