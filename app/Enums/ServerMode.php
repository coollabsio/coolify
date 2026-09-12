<?php

namespace App\Enums;

enum ServerMode: string
{
    case LEGACY = 'legacy';
    case V5_WORKER = 'v5-worker';
    case V5_COMBINED = 'v5-combined';
    case V5_CONTROL_PLANE = 'v5-control-plane';

    public function usesPodman(): bool
    {
        return in_array($this, [self::V5_WORKER, self::V5_COMBINED], true);
    }
}
