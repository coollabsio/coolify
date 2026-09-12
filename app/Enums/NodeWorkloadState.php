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

    public function badgeType(): string
    {
        return match ($this) {
            self::RUNNING => 'success',
            self::STOPPED, self::OUTDATED, self::MISSING, self::STALE => 'warning',
            self::UNKNOWN => 'neutral',
        };
    }
}
