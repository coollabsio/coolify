<?php

namespace App\Enums;

enum ServerRole: string
{
    case DEPLOYMENT = 'deployment';
    case BUILD = 'build';
    case BOTH = 'both';

    public function canBuild(): bool
    {
        return $this !== self::DEPLOYMENT;
    }

    public function canDeploy(): bool
    {
        return $this !== self::BUILD;
    }
}
