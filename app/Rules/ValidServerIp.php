<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidServerIp implements ValidationRule
{
    /**
     * Accepts a valid IPv4 address, IPv6 address, or RFC 1123 hostname.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $trimmed = trim($value);

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return;
        }

        // Delegate hostname validation to ValidHostname
        $hostnameRule = new ValidHostname;
        $failed = false;
        $hostnameRule->validate($attribute, $trimmed, function () use (&$failed) {
            $failed = true;
        });

        if ($failed) {
            $fail('The :attribute must be a valid IPv4 address, IPv6 address, or hostname.');
        }
    }
}
