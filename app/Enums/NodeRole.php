<?php

namespace App\Enums;

enum NodeRole: string
{
    case WORKER = 'worker';
    case CONTROLLER_WORKER = 'controller-worker';
    case CONTROLLER = 'controller';

    public function runsWorkloads(): bool
    {
        return $this !== self::CONTROLLER;
    }
}
