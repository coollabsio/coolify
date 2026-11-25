<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Proxy IP allowlist: IPv4 or IPv6 addresses and CIDR ranges, separated by commas or whitespace.
 */
class ValidIpAllowlist implements ValidationRule
{
    /**
     * @return list<string>
     */
    public static function entries(?string $value): array
    {
        return array_values(array_unique(preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY)));
    }

    /**
     * @return list<string>
     */
    public static function validEntries(?string $value): array
    {
        return array_values(array_filter(self::entries($value), self::isValidEntry(...)));
    }

    public static function isValidEntry(string $entry): bool
    {
        $parts = explode('/', $entry);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }

        $maxPrefix = str_contains($parts[0], ':') ? 128 : 32;

        return ctype_digit($parts[1]) && (int) $parts[1] <= $maxPrefix;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a list of IP addresses or CIDR ranges.');

            return;
        }

        $invalidEntries = array_diff(self::entries($value), self::validEntries($value));
        if ($invalidEntries !== []) {
            $fail('The following entries are not valid IP addresses or CIDR ranges: '.implode(', ', $invalidEntries));
        }
    }
}
