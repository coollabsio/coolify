<?php

namespace App\Rules;

use App\Models\InstanceSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;
use PurplePixie\PhpDns\DNSQuery;
use PurplePixie\PhpDns\DNSTypes;
use Throwable;

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
     * private/reserved ranges, and dangerous hostnames unless the
     * instance operator explicitly allowlists the intranet target.
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

        if (str_ends_with($host, '.')) {
            $fail('The :attribute host must not end with a trailing dot.');

            return;
        }

        $host = strtolower($host);
        $hostForIpCheck = $this->normalizeHostForIpCheck($host);
        $hostForDns = rtrim($hostForIpCheck, '.');

        if ($this->isBlockedHostname($hostForDns) && ! $this->isAllowedHostname($hostForDns)) {
            $this->logBlockedHost($attribute, $host);
            $fail('The :attribute must not point to localhost or internal hosts.');

            return;
        }

        if (filter_var($hostForIpCheck, FILTER_VALIDATE_IP)) {
            if (! $this->isAllowedIp($hostForIpCheck, $hostForDns)) {
                $this->logBlockedIp($attribute, $host, $hostForIpCheck);
                $fail('The :attribute must not point to private, reserved, loopback, or link-local addresses.');

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
            if (! $this->isAllowedIp($resolvedIp, $hostForDns)) {
                $this->logBlockedIp($attribute, $host, $resolvedIp);
                $fail('The :attribute must not point to private, reserved, loopback, or link-local addresses.');

                return;
            }
        }
    }

    /**
     * Build HTTP client options that pin the validated host to the resolved IPs.
     *
     * @return array<string, mixed>
     */
    public static function httpClientOptions(string $url): array
    {
        $options = ['allow_redirects' => false];

        if (! defined('CURLOPT_RESOLVE')) {
            throw new \RuntimeException('Webhook URL DNS pinning is unavailable.');
        }

        $target = self::resolveUrlForRequest($url);

        if ($target['ips'] === [] || filter_var($target['host'], FILTER_VALIDATE_IP)) {
            return $options;
        }

        $options['curl'] = [
            CURLOPT_RESOLVE => array_map(
                fn (string $ip): string => sprintf('%s:%d:%s', $target['host'], $target['port'], filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$ip.']' : $ip),
                $target['ips'],
            ),
        ];

        return $options;
    }

    /**
     * Build mc --resolve mappings that pin the endpoint host for S3 backups.
     *
     * @return array<int, string>
     */
    public static function minioClientResolveOptions(string $url): array
    {
        $target = self::resolveUrlForRequest($url);

        if ($target['ips'] === [] || filter_var($target['host'], FILTER_VALIDATE_IP)) {
            return [];
        }

        return array_map(
            fn (string $ip): string => sprintf('%s:%d=%s', $target['host'], $target['port'], filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$ip.']' : $ip),
            $target['ips'],
        );
    }

    public static function redactedUrlForLog(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($scheme) || ! is_string($host)) {
            return '[invalid-url]';
        }

        return strtolower($scheme).'://'.strtolower($host).($port ? ':'.$port : '');
    }

    /**
     * @return array{host: string, port: int, ips: array<int, string>}
     */
    private static function resolveUrlForRequest(string $url): array
    {
        $rule = new self;
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new \RuntimeException('Webhook URL host could not be resolved.');
        }

        if (str_ends_with($host, '.')) {
            throw new \RuntimeException('Webhook URL host must not end with a trailing dot.');
        }

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);
        $hostForDns = rtrim($rule->normalizeHostForIpCheck(strtolower($host)), '.');

        if (filter_var($hostForDns, FILTER_VALIDATE_IP)) {
            if (! $rule->isAllowedIp($hostForDns, $hostForDns)) {
                throw new \RuntimeException('Webhook URL resolved to an unsafe IP address.');
            }

            return ['host' => $hostForDns, 'port' => $port, 'ips' => []];
        }

        $resolvedIps = $rule->resolveHost($hostForDns);
        if ($resolvedIps === []) {
            throw new \RuntimeException('Webhook URL host could not be resolved.');
        }

        foreach ($resolvedIps as $resolvedIp) {
            if (! $rule->isAllowedIp($resolvedIp, $hostForDns)) {
                throw new \RuntimeException('Webhook URL resolved to an unsafe IP address.');
            }
        }

        return ['host' => $hostForDns, 'port' => $port, 'ips' => $resolvedIps];
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

        if ($host === 'localhost') {
            return ['127.0.0.1', '::1'];
        }

        $customDnsServers = $this->customDnsServers();
        if ($customDnsServers !== []) {
            return $this->resolveHostWithCustomDnsServers($host, $customDnsServers);
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

    /**
     * @param  array<int, string>  $dnsServers
     * @return array<int, string>
     */
    private function resolveHostWithCustomDnsServers(string $host, array $dnsServers): array
    {
        $ips = [];

        foreach ($dnsServers as $dnsServer) {
            foreach ([DNSTypes::NAME_A, DNSTypes::NAME_AAAA] as $type) {
                try {
                    $query = new DNSQuery($dnsServer, 53, 5);
                    $records = $query->query($host, $type);

                    if ($records === false || $query->hasError()) {
                        continue;
                    }

                    foreach ($records as $record) {
                        if ($record->getType() === $type && filter_var($record->getData(), FILTER_VALIDATE_IP)) {
                            $ips[] = $record->getData();
                        }
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return array<int, string>
     */
    private function customDnsServers(): array
    {
        $servers = $this->instanceSettings()?->custom_dns_servers ?? '';

        if (! is_string($servers)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $server): string => trim($server),
            explode(',', $servers),
        ), fn (string $server): bool => filter_var($server, FILTER_VALIDATE_IP) !== false));
    }

    private function isAllowedIp(string $ip, string $host): bool
    {
        $embeddedIpv4 = $this->extractIpv4FromMappedIpv6($ip);
        if ($embeddedIpv4 !== null) {
            $ip = $embeddedIpv4;
        }

        if ($this->isPublicIp($ip)) {
            return true;
        }

        if ($this->isLocalhostIp($ip)) {
            return $this->allowLocalhost()
                && ($this->isAllowedHostname($host) || $this->isAllowlistedIp($ip));
        }

        if ($this->isPrivateIp($ip)) {
            return $this->isAllowedHostname($host) || $this->isAllowlistedIp($ip);
        }

        return $this->isAllowlistedIp($ip);
    }

    private function isPublicIp(string $ip): bool
    {
        $embeddedIpv4 = $this->extractIpv4FromMappedIpv6($ip);
        if ($embeddedIpv4 !== null) {
            return filter_var($embeddedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
                && ! $this->isSpecialUseIpv4($embeddedIpv4);
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! $this->isSpecialUseIp($ip);
    }

    private function isLocalhostIp(string $ip): bool
    {
        $embeddedIpv4 = $this->extractIpv4FromMappedIpv6($ip);
        if ($embeddedIpv4 !== null) {
            return $this->isLocalhostIp($embeddedIpv4);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->ipv4InCidr($ip, '127.0.0.0/8');
        }

        return @inet_pton($ip) === @inet_pton('::1');
    }

    private function isPrivateIp(string $ip): bool
    {
        $embeddedIpv4 = $this->extractIpv4FromMappedIpv6($ip);
        if ($embeddedIpv4 !== null) {
            $ip = $embeddedIpv4;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
            && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function isSpecialUseIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isSpecialUseIpv4($ip);
        }

        return $this->isSpecialUseIpv6($ip);
    }

    private function isSpecialUseIpv4(string $ip): bool
    {
        foreach ([
            '0.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
            '255.255.255.255/32',
        ] as $cidr) {
            if ($this->ipv4InCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function isSpecialUseIpv6(string $ip): bool
    {
        $ipBytes = @inet_pton($ip);
        if ($ipBytes === false) {
            return false;
        }

        foreach ([
            '::/128',
            '::1/128',
            '::ffff:0:0/96',
            '64:ff9b::/96',
            '100::/64',
            '2001::/23',
            '2001:2::/48',
            '2001:db8::/32',
            '2002::/16',
            'fc00::/7',
            'fe80::/10',
            'ff00::/8',
        ] as $cidr) {
            [$network, $prefix] = explode('/', $cidr, 2);
            $networkBytes = @inet_pton($network);
            if ($networkBytes !== false && $this->binaryInCidr($ipBytes, $networkBytes, (int) $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedHostname(string $host): bool
    {
        return in_array($host, ['localhost'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.cluster.local');
    }

    private function isAllowedHostname(string $host): bool
    {
        foreach ($this->allowlistEntries() as $entry) {
            if (! str_contains($entry, '/') && strtolower($entry) === $host) {
                return true;
            }
        }

        return false;
    }

    private function isAllowlistedIp(string $ip): bool
    {
        foreach ($this->allowlistEntries() as $entry) {
            if (str_contains($entry, '/')) {
                if ($this->ipInCidr($ip, $entry)) {
                    return true;
                }

                continue;
            }

            if (filter_var($entry, FILTER_VALIDATE_IP) && @inet_pton($entry) === @inet_pton($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function allowlistEntries(): array
    {
        $entries = $this->instanceSettings()?->webhook_allowed_internal_hosts ?? [];

        if (is_string($entries)) {
            $entries = explode(',', $entries);
        }

        if (! is_array($entries)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $entry): string => rtrim(strtolower(trim((string) $entry)), '.'),
            $entries,
        )));
    }

    private function allowLocalhost(): bool
    {
        return (bool) ($this->instanceSettings()?->webhook_allow_localhost ?? false);
    }

    private function instanceSettings(): ?InstanceSettings
    {
        try {
            return InstanceSettings::query()->find(0);
        } catch (Throwable) {
            return null;
        }
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($network === null || $prefix === null || ! is_numeric($prefix)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->ipv4InCidr($ip, $cidr);
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || ! filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        $prefix = (int) $prefix;
        if ($prefix < 0 || $prefix > 128) {
            return false;
        }

        $ipBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($ipBytes === false || $networkBytes === false) {
            return false;
        }

        return $this->binaryInCidr($ipBytes, $networkBytes, $prefix);
    }

    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($network === null || $prefix === null || ! is_numeric($prefix)) {
            return false;
        }

        $prefix = (int) $prefix;
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $networkLong = ip2long($network);
        if ($ipLong === false || $networkLong === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));

        return ($ipLong & $mask) === ($networkLong & $mask);
    }

    private function binaryInCidr(string $ipBytes, string $networkBytes, int $prefix): bool
    {
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($ipBytes, 0, $bytes) !== substr($networkBytes, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $bits) & 0xFF;

        return (ord($ipBytes[$bytes]) & $mask) === (ord($networkBytes[$bytes]) & $mask);
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
