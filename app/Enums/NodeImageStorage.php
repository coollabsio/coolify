<?php

namespace App\Enums;

/** Where the builds of a cluster are stored, so that its Nodes can pull them. */
enum NodeImageStorage: string
{
    /** One repository in a registry of the team, for example GHCR or Docker Hub. */
    case EXTERNAL = 'external';

    /** zot on one Node of the cluster (decision 0006). */
    case BUILT_IN = 'built_in';

    /** The Podman store of the only Node. Never stored: NodeCluster::imageStorage() derives it. */
    case LOCAL = 'local';
}
