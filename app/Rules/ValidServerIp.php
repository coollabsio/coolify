<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidServerIp implements ValidationRule
{
    /**
     * Accepts a valid IPv4 address, IPv6 address, or RFC 1123 hostname.
     *
     * IP literals in private/reserved ranges (loopback, link-local, RFC 1918,
     * etc.) are rejected by default to stop a member from pointing a server at
     * the Coolify host's internal network and abusing the synchronous SSH check
     * to probe it. Self-hosters on private LANs can allow them via
     * config('coold.allow_private_server_ips').
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $trimmed = trim($value);

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->failIfDisallowedRange($trimmed, $fail);

            return;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->failIfDisallowedRange($trimmed, $fail);

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

    /**
     * Reject IPs in private/reserved ranges unless the operator has explicitly
     * opted in. The IP is already known to be a valid literal here.
     */
    private function failIfDisallowedRange(string $ip, Closure $fail): void
    {
        if (config('coold.allow_private_server_ips')) {
            return;
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic === false) {
            $fail('The :attribute must not be a private or reserved IP address.');
        }
    }
}
