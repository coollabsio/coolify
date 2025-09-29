<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidIpOrCidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            // Empty is allowed (means allow from anywhere)
            return;
        }

        // Special case: 0.0.0.0 is allowed
        if ($value === '0.0.0.0') {
            return;
        }

        $entries = explode(',', $value);
        $invalidEntries = [];

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if (empty($entry)) {
                continue;
            }

            // Special case: 0.0.0.0 with or without subnet
            if (str_starts_with($entry, '0.0.0.0')) {
                continue;
            }

            // Check if it's CIDR notation
            if (str_contains($entry, '/')) {
                $parts = explode('/', $entry);
                if (count($parts) !== 2) {
                    $invalidEntries[] = $entry;

                    continue;
                }

                [$ip, $mask] = $parts;

                if (! filter_var($ip, FILTER_VALIDATE_IP) || ! is_numeric($mask) || $mask < 0 || $mask > 32) {
                    $invalidEntries[] = $entry;
                }
            } else {
                // Check if it's a valid IP
                if (! filter_var($entry, FILTER_VALIDATE_IP)) {
                    $invalidEntries[] = $entry;
                }
            }
        }

        if (! empty($invalidEntries)) {
            $fail('The following entries are not valid IP addresses or CIDR notations: '.implode(', ', $invalidEntries));
        }
    }
}
