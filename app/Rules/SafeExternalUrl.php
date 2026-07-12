<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class SafeExternalUrl implements ValidationRule
{
    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver
     */
    public function __construct(private ?Closure $resolver = null) {}

    /**
     * Run the validation rule.
     *
     * Validates that a URL points to an external, publicly-routable host.
     * Blocks private IP ranges, reserved ranges, localhost, and link-local
     * addresses to prevent Server-Side Request Forgery (SSRF).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $scheme = strtolower(parse_url($value, PHP_URL_SCHEME) ?? '');
        if (! in_array($scheme, ['https', 'http'])) {
            $fail('The :attribute must use the http or https scheme.');

            return;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! $host) {
            $fail('The :attribute must contain a valid host.');

            return;
        }

        $host = strtolower($host);
        $hostForIpCheck = $this->normalizeHostForIpCheck($host);
        $hostForDns = rtrim($hostForIpCheck, '.');

        $internalHosts = ['localhost', '0.0.0.0', '::1'];
        if (in_array($hostForDns, $internalHosts, true) || str_ends_with($hostForDns, '.local') || str_ends_with($hostForDns, '.internal')) {
            $this->logBlockedHost($attribute, $value, $host);
            $fail('The :attribute must not point to internal hosts.');

            return;
        }

        if (filter_var($hostForIpCheck, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($hostForIpCheck)) {
                $this->logBlockedIp($attribute, $value, $host, $hostForIpCheck);
                $fail('The :attribute must not point to a private or reserved IP address.');

                return;
            }

            return;
        }

        $resolvedIps = $this->resolveHost($hostForDns);
        if ($resolvedIps === []) {
            $fail('The :attribute host could not be resolved.');

            return;
        }

        foreach ($resolvedIps as $resolvedIp) {
            if (! $this->isPublicIp($resolvedIp)) {
                $this->logBlockedIp($attribute, $value, $host, $resolvedIp);
                $fail('The :attribute must not point to a private or reserved IP address.');

                return;
            }
        }
    }

    private function normalizeHostForIpCheck(string $host): string
    {
        return (str_starts_with($host, '[') && str_ends_with($host, ']'))
            ? substr($host, 1, -1)
            : $host;
    }

    /**
     * @return array<int, string>
     */
    private function resolveHost(string $host): array
    {
        if ($this->resolver instanceof Closure) {
            return array_values(array_filter(($this->resolver)($host), fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            $records = [];
        }

        $ips = [];
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_IP)) {
                    $ips[] = $record[$key];
                }
            }
        }

        $ipv4Addresses = @gethostbynamel($host);
        if (is_array($ipv4Addresses)) {
            foreach ($ipv4Addresses as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        $embeddedIpv4 = $this->extractIpv4FromMappedIpv6($ip);
        if ($embeddedIpv4 !== null) {
            return filter_var($embeddedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function extractIpv4FromMappedIpv6(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $prefix = substr($packed, 0, 12);
        if ($prefix !== str_repeat("\0", 10)."\xff\xff") {
            return null;
        }

        $parts = unpack('C4', substr($packed, 12, 4));
        if ($parts === false) {
            return null;
        }

        return implode('.', $parts);
    }

    private function logBlockedHost(string $attribute, string $url, string $host): void
    {
        Log::warning('External URL points to internal host', [
            'attribute' => $attribute,
            'url' => $url,
            'host' => $host,
            'ip' => request()->ip(),
            'user_id' => auth()->id(),
        ]);
    }

    private function logBlockedIp(string $attribute, string $url, string $host, string $resolvedIp): void
    {
        Log::warning('External URL resolves to private or reserved IP', [
            'attribute' => $attribute,
            'url' => $url,
            'host' => $host,
            'resolved_ip' => $resolvedIp,
            'ip' => request()->ip(),
            'user_id' => auth()->id(),
        ]);
    }
}
