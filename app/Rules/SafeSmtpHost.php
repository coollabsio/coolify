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
    /** Exception code: the host is invalid or points to a blocked address. Sending again fails again. */
    public const HOST_NOT_ALLOWED = 1;

    /** Exception code: the host did not resolve. This can be temporary, so the send can be retried. */
    public const HOST_UNRESOLVED = 2;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $host = is_string($value) ? self::normalizeHost($value) : '';
        if (! self::isValidHost($host)) {
            $fail('The SMTP host must be a valid hostname or IP address.');

            return;
        }

        $message = match (SafeWebhookUrl::forSmtpHost()->checkConnectionHost($host)['status']) {
            'allowed' => null,
            'unresolved' => 'The SMTP host could not be resolved.',
            'link_local' => 'The SMTP host must not point to link-local addresses.',
            'blocked' => isInstanceAdmin()
                ? 'The SMTP host points to a local or private address that is not allowed. Configure allowed internal targets: '.route('settings.advanced').'#endpoint-section'
                : 'The SMTP host points to a local or private address that is not allowed. Ask your Coolify administrator to allow this host.',
        };

        if ($message !== null) {
            $fail($message);
        }
    }

    /**
     * Check the host again just before a connection and return the address to connect to,
     * so a DNS change after validation cannot point the connection at a blocked address.
     *
     * @throws RuntimeException With code HOST_NOT_ALLOWED or HOST_UNRESOLVED.
     */
    public static function connectionAddress(string $host): string
    {
        $host = self::normalizeHost($host);
        if (! self::isValidHost($host)) {
            throw new RuntimeException('The SMTP host is not a valid hostname or IP address.', self::HOST_NOT_ALLOWED);
        }

        ['status' => $status, 'ips' => $ips] = SafeWebhookUrl::forSmtpHost()->checkConnectionHost($host);

        if ($status === 'unresolved') {
            throw new RuntimeException('The SMTP host could not be resolved.', self::HOST_UNRESOLVED);
        }

        if ($status !== 'allowed') {
            throw new RuntimeException('The SMTP host is not allowed: it points to a local or private address.', self::HOST_NOT_ALLOWED);
        }

        if ($ips === []) {
            return $host;
        }

        $ipv4 = array_values(array_filter($ips, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));

        return $ipv4[0] ?? $ips[0];
    }

    /**
     * The host to check and connect to: an IP address without brackets, or a lower-case host name
     * without the trailing dot of a fully qualified name (smtp.gmail.com.). TLS verifies this name.
     */
    public static function normalizeHost(string $host): string
    {
        $host = normalizeIpAddress($host);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $host = strtolower($host);

        return str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
    }

    /**
     * An IP address, or a host name whose labels have letters, digits, hyphens, and
     * underscores (Docker service names such as mail_server).
     */
    private static function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match('/\A[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\z/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
