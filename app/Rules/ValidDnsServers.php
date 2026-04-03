<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDnsServers implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $entries = explode(',', $value);
        $invalidEntries = [];

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if (empty($entry)) {
                continue;
            }

            if (! filter_var($entry, FILTER_VALIDATE_IP)) {
                $invalidEntries[] = $entry;
            }
        }

        if (! empty($invalidEntries)) {
            $fail('The following entries are not valid DNS server IP addresses: '.implode(', ', $invalidEntries));
        }
    }
}
