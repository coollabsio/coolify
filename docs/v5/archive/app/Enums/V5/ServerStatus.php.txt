<?php

namespace App\Enums\V5;

/**
 * Lifecycle states persisted on `v5_servers.status`.
 */
enum ServerStatus: string
{
    case Added = 'added';
    case Installed = 'installed';
    case Failed = 'failed';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';
}
