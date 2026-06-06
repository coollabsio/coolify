<?php

namespace App\Enums;

enum DockerNetworkRole: string
{
    case DefaultDestination = 'default_destination';
    case ResourceStack = 'resource_stack';
    case PreviewStack = 'preview_stack';
    case PrivateInternal = 'private_internal';
    case SharedExternal = 'shared_external';
    case ManagedCustom = 'managed_custom';
    case System = 'system';
    case Unknown = 'unknown';
}
