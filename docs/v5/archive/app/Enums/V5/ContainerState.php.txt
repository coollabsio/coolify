<?php

namespace App\Enums\V5;

/**
 * Runtime states persisted on `v5_container_statuses.status`.
 *
 * Named ContainerState (not ContainerStatus) to avoid clashing with the
 * App\Models\V5\ContainerStatus Eloquent model. Covers the Docker and Podman
 * container states reported by coold.
 */
enum ContainerState: string
{
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
    case Unknown = 'unknown';
}
