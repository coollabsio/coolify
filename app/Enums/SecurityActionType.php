<?php

namespace App\Enums;

/**
 * What fixes a relevant finding of a scanned image (spec 15.5). The cases are in the order the actions are listed.
 */
enum SecurityActionType: string
{
    case UpdateImage = 'update_image';
    case PullLatest = 'pull_latest';
    case ChangeBase = 'change_base';
    case Rebuild = 'rebuild';
    case UpgradeInDockerfile = 'upgrade_in_dockerfile';
    case UpgradePackage = 'upgrade_package';
    case RebuildInCi = 'rebuild_in_ci';
}
