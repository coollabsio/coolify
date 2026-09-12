<?php

namespace App\Enums;

enum NodeOperationStatus: string
{
    case QUEUED = 'queued';
    case DISPATCHED = 'dispatched';
    case RUNNING = 'running';
    case VERIFYING = 'verifying';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case TIMED_OUT = 'timed_out';
    case UNCERTAIN = 'uncertain';
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED, self::TIMED_OUT, self::CANCELLED], true);
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::QUEUED => in_array($status, [self::DISPATCHED, self::FAILED, self::CANCELLED], true),
            self::DISPATCHED => in_array($status, [self::RUNNING, self::SUCCEEDED, self::FAILED, self::TIMED_OUT, self::UNCERTAIN, self::CANCELLED], true),
            self::RUNNING => in_array($status, [self::VERIFYING, self::FAILED, self::TIMED_OUT, self::UNCERTAIN, self::CANCELLED], true),
            self::VERIFYING => in_array($status, [self::SUCCEEDED, self::FAILED, self::TIMED_OUT, self::UNCERTAIN, self::CANCELLED], true),
            self::UNCERTAIN => in_array($status, [self::DISPATCHED, self::SUCCEEDED, self::FAILED, self::CANCELLED], true),
            self::SUCCEEDED, self::FAILED, self::TIMED_OUT, self::CANCELLED => false,
        };
    }
}
