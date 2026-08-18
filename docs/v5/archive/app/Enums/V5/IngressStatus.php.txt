<?php

namespace App\Enums\V5;

/**
 * States persisted on `v5_servers.ingress_status`.
 *
 * The value mirrors the ingress proxy container's runtime state as reported
 * by coold, so the Docker and Podman container states are part of the catalog.
 */
enum IngressStatus: string
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
