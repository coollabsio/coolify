<?php

namespace App\Enums;

/**
 * Where Sentinel runs on a server: in the coolify-sentinel container, or as the sentinel.service systemd unit.
 */
enum SentinelRuntime: string
{
    case Docker = 'docker';
    case Host = 'host';

    /**
     * The command that reads a shell script from stdin where Sentinel listens on 127.0.0.1:8888.
     * It has no pipe or `sh -c`, so the non-root sudo parser only puts sudo in front of it.
     */
    public function shellCommand(): string
    {
        return match ($this) {
            self::Docker => 'docker exec -i coolify-sentinel sh -s',
            self::Host => 'sh -s',
        };
    }
}
