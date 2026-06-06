<?php

namespace App\Enums;

enum DockerNetworkScope: string
{
    case Local = 'local';
    case Swarm = 'swarm';
    case Global = 'global';
    case Unknown = 'unknown';
}
