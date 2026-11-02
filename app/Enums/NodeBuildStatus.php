<?php

namespace App\Enums;

enum NodeBuildStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED, self::CANCELLED], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The `type` of `x-status-badge`. */
    public function badgeType(): string
    {
        return match ($this) {
            self::SUCCEEDED => 'success',
            self::FAILED => 'error',
            self::QUEUED, self::RUNNING => 'warning',
            self::CANCELLED => 'neutral',
        };
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::QUEUED->value, self::RUNNING->value];
    }
}
