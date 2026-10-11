<?php

namespace App\Enums;

enum GithubRunnerDockerMode: string
{
    /** Jobs get their own privileged Docker daemon in a sidecar container. */
    case Dind = 'dind';

    /** Jobs get their own Docker daemon in an unprivileged Sysbox sidecar container. */
    case Sysbox = 'sysbox';

    /** Jobs get no Docker access. */
    case None = 'none';
}
