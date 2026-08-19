<?php

namespace App\Enums\V5;

/**
 * Lifecycle states persisted on `v5_applications.status`.
 *
 * Besides Coolify's own states (creating, failed, unknown), the column also
 * receives raw container runtime states reported by coold, so the Docker and
 * Podman container states are part of the catalog.
 */
enum ApplicationStatus: string
{
    case Creating = 'creating';
    case Configured = 'configured';
    case Created = 'created';
    case Starting = 'starting';
    case Running = 'running';
    case Restarting = 'restarting';
    case Paused = 'paused';
    case Removing = 'removing';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Exited = 'exited';
    case Dead = 'dead';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
