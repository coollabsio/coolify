<?php

namespace App\Enums;

/** Where the builds of a cluster are stored, so that its Nodes can run them. */
enum NodeImageStorage: string
{
    /** One repository in a registry of the team, for example GHCR or Docker Hub. */
    case EXTERNAL = 'external';

    /** The Podman store of the build Node. The other Nodes copy the image from a Node that has it (decision 0030). */
    case LOCAL = 'local';
}
