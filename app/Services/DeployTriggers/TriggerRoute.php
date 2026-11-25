<?php

namespace App\Services\DeployTriggers;

enum TriggerRoute: string
{
    case DeployNow = 'deploy_now';
    case Evaluate = 'evaluate';
}
