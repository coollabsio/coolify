<?php

namespace App\Enums;

enum NodeContainerManagementState: string
{
    case MANAGED = 'managed';
    case EXTERNAL = 'external';
    case UNRECOGNIZED = 'unrecognized';
}
