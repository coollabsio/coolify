<?php

namespace App\Enums;

enum ContainerStatusTypes: string
{
    case PAUSED = 'paused';
    case RESTARTING = 'restarting';
    case REMOVING = 'removing';
    case RUNNING = 'running';
    case DEAD = 'dead';
    case CREATED = 'created';
    case EXITED = 'exited';
}
