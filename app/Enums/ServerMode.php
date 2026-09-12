<?php

namespace App\Enums;

enum ServerMode: string
{
    case LEGACY = 'legacy';
    case NODE_WORKER = 'node-worker';
    case NODE_CONTROLLER_WORKER = 'node-controller-worker';
    case NODE_CONTROLLER = 'node-controller';

    public function usesPodman(): bool
    {
        return in_array($this, [self::NODE_WORKER, self::NODE_CONTROLLER_WORKER], true);
    }
}
