<?php

namespace App\Enums;

use App\Models\Server;

/**
 * How Sentinel stores client IPs for the "Top IPs" traffic breakdown (TRAFFIC_IP_MODE).
 */
enum TrafficIpMode: string
{
    case Full = 'full';
    case Anonymized = 'anonymized';
    case Off = 'off';

    public static function forServer(Server $server): self
    {
        return $server->settings?->traffic_ip_mode ?? self::Full;
    }

    /**
     * Effective mode for a view that merges several servers: Off only when every server is off,
     * Anonymized when every server that records IPs anonymizes them, otherwise Full.
     *
     * @param  iterable<Server>  $servers
     */
    public static function forServers(iterable $servers): self
    {
        $modes = collect($servers)->map(fn (Server $server): self => self::forServer($server));

        if ($modes->isEmpty()) {
            return self::Full;
        }

        $recording = $modes->reject(fn (self $mode): bool => $mode === self::Off);

        if ($recording->isEmpty()) {
            return self::Off;
        }

        return $recording->every(fn (self $mode): bool => $mode === self::Anonymized)
            ? self::Anonymized
            : self::Full;
    }

    /**
     * Drop the `ip` dimension when Sentinel does not record it.
     *
     * @param  array<int, string>  $dimensions
     * @return array<int, string>
     */
    public function breakdownDimensions(array $dimensions): array
    {
        if ($this !== self::Off) {
            return $dimensions;
        }

        return array_values(array_filter($dimensions, fn (string $dimension): bool => $dimension !== 'ip'));
    }

    public function breakdownLabel(): string
    {
        return $this === self::Anonymized ? 'Top networks' : 'Top IPs';
    }

    public function breakdownHelper(): string
    {
        return $this === self::Anonymized
            ? 'Busiest client networks. IPs are anonymized to /24 (IPv4) and /48 (IPv6).'
            : 'Busiest client IPs (real visitor IP, resolved behind Cloudflare / reverse proxies).';
    }
}
