<?php

namespace App\Enums;

enum NodeWorkloadDesiredState: string
{
    case RUNNING = 'running';
    case STOPPED = 'stopped';
    case REMOVED = 'removed';
}
