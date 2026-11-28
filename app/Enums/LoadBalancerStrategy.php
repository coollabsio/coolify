<?php

namespace App\Enums;

enum LoadBalancerStrategy: string
{
    case RoundRobin = 'round_robin';
    case LeastConnections = 'least_connections';
    case LeastTime = 'least_time';
    case ClientIp = 'client_ip';

    public function label(): string
    {
        return match ($this) {
            self::RoundRobin => 'Round robin',
            self::LeastConnections => 'Least connections',
            self::LeastTime => 'Fastest response',
            self::ClientIp => 'Client IP',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RoundRobin => 'Each replica in turn.',
            self::LeastConnections => 'The replica with the fewest open requests.',
            self::LeastTime => 'The replica that answers fastest.',
            self::ClientIp => 'The IP address of the visitor picks the replica.',
        };
    }

    public function traefikStrategy(): ?string
    {
        return match ($this) {
            self::RoundRobin => null,
            self::LeastConnections => 'p2c',
            self::LeastTime => 'leasttime',
            self::ClientIp => 'hrw',
        };
    }

    public function minimumTraefikVersion(): ?string
    {
        return match ($this) {
            self::RoundRobin => null,
            self::LeastConnections => '3.4',
            self::LeastTime, self::ClientIp => '3.6',
        };
    }

    public function caddyPolicy(): ?string
    {
        return match ($this) {
            self::RoundRobin => 'round_robin',
            self::LeastConnections => 'least_conn',
            self::LeastTime => null,
            self::ClientIp => 'client_ip_hash',
        };
    }
}
