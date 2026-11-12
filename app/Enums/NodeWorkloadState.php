<?php

namespace App\Enums;

enum NodeWorkloadState: string
{
    case RUNNING = 'running';
    case STOPPED = 'stopped';
    case OUTDATED = 'outdated';
    case MISSING = 'missing';
    case UNKNOWN = 'unknown';
    case STALE = 'stale';
    /** The server is offline (decision 0014): the application does not run there until it comes back or moves. */
    case SERVER_OFFLINE = 'server offline';

    public function badgeType(): string
    {
        return match ($this) {
            self::RUNNING => 'success',
            self::STOPPED, self::OUTDATED, self::MISSING, self::STALE => 'warning',
            self::UNKNOWN => 'neutral',
            self::SERVER_OFFLINE => 'error',
        };
    }
}
