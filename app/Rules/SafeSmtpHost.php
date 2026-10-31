<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RuntimeException;

/**
 * The SMTP host of team email settings. Coolify connects to it from its own container, so it
 * gets the same targets as git sources: private networks are allowed on self-hosted instances
 * and blocked on Coolify Cloud. Loopback and link-local (cloud metadata) addresses are always
 * blocked, unless an instance admin allowlists them.
 */
class SafeSmtpHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $host = is_string($value) ? normalizeIpAddress($value) : '';
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            $fail('The :attribute must be a valid hostname or IP address.');

            return;
        }

        SafeWebhookUrl::forGitSource()->validate($attribute, 'https://'.formatHostForUrl($host), $fail);
    }

    /**
     * Check the host again just before a connection and return the address to connect to,
     * so a DNS change after validation cannot point the connection at a blocked address.
     *
     * @throws RuntimeException When the host is not allowed.
     */
    public static function connectionAddress(string $host, int $port): string
    {
        $host = normalizeIpAddress($host);

        try {
            $ips = SafeWebhookUrl::resolveHostForConnection($host, $port, SafeWebhookUrl::gitSourcesMayUsePrivateNetworks());
        } catch (RuntimeException) {
            throw new RuntimeException('The SMTP host is not allowed: it could not be resolved, or it points to a local or private address.');
        }

        if ($ips === []) {
            return $host;
        }

        $ipv4 = array_values(array_filter($ips, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));

        return $ipv4[0] ?? $ips[0];
    }
}
