<?php

namespace App\Enums;

enum DockerNetworkSourceType: string
{
    case StandaloneDockerDestination = 'standalone_docker_destination';
    case SwarmDockerDestination = 'swarm_docker_destination';
    case ComposeStackDefault = 'compose_stack_default';
    case ServiceStackDefault = 'service_stack_default';
    case PreviewDeployment = 'preview_deployment';
    case ManagedCustom = 'managed_custom';
    case ImportedExternal = 'imported_external';
    case System = 'system';
    case Unknown = 'unknown';
}
