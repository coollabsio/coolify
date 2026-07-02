<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class SafeWebhookUrl implements ValidationRule
{
    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver
     */
    public function __construct(private ?Closure $resolver = null) {}

    /**
     * Run the validation rule.
     *
     * Validates that a webhook URL is safe for server-side requests.
     * Blocks loopback addresses, cloud metadata endpoints (link-local),
     * and dangerous hostnames while allowing private network IPs
     * for self-hosted deployments.
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

        $blockedHosts = ['localhost', '0.0.0.0', '::1'];
        if (in_array($hostForDns, $blockedHosts, true) || str_ends_with($hostForDns, '.internal')) {
            $this->logBlockedHost($attribute, $host);
            $fail('The :attribute must not point to localhost or internal hosts.');

            return;
        }

        if (filter_var($hostForIpCheck, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($hostForIpCheck)) {
                $this->logBlockedIp($attribute, $host, $hostForIpCheck);
                $fail('The :attribute must not point to loopback or link-local addresses.');

                return;
            }

            return;
        }

        $resolvedIps = $this->resolveHost($hostForDns);
        foreach ($resolvedIps as $resolvedIp) {
            if ($this->isBlockedIp($resolvedIp)) {
                $this->logBlockedIp($attribute, $host, $resolvedIp);
                $fail('The :attribute must not point to loopback or link-local addresses.');

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

    private function isBlockedIp(string $ip): bool
    {
        $embeddedIpv4 = $this->extractIpv4FromMappedIpv6($ip);
        if ($embeddedIpv4 !== null) {
            return $this->isBlockedIpv4($embeddedIpv4);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isBlockedIpv4($ip);
        }

        return $this->isBlockedIpv6($ip);
    }

    private function isBlockedIpv4(string $ip): bool
    {
        if ($ip === '0.0.0.0' || str_starts_with($ip, '127.')) {
            return true;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        $unsigned = sprintf('%u', $long);
        $linkLocalStart = sprintf('%u', ip2long('169.254.0.0'));
        $linkLocalEnd = sprintf('%u', ip2long('169.254.255.255'));

        return $unsigned >= $linkLocalStart && $unsigned <= $linkLocalEnd;
    }

    private function isBlockedIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if ($packed === inet_pton('::1') || $packed === inet_pton('::')) {
            return true;
        }

        $bytes = unpack('C16', $packed);
        if ($bytes === false) {
            return false;
        }

        $firstByte = $bytes[1];
        $secondByte = $bytes[2];

        // fe80::/10 link-local and fc00::/7 unique local addresses.
        return ($firstByte === 0xFE && ($secondByte & 0xC0) === 0x80)
            || (($firstByte & 0xFE) === 0xFC);
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

    private function logBlockedHost(string $attribute, string $host): void
    {
        Log::warning('Webhook URL points to blocked host', [
            'attribute' => $attribute,
            'host' => $host,
            'ip' => request()->ip(),
            'user_id' => auth()->id(),
        ]);
    }

    private function logBlockedIp(string $attribute, string $host, string $blockedIp): void
    {
        Log::warning('Webhook URL points to blocked IP range', [
            'attribute' => $attribute,
            'host' => $host,
            'resolved_ip' => $blockedIp,
            'ip' => request()->ip(),
            'user_id' => auth()->id(),
        ]);
    }
}
