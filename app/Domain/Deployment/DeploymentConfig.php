<?php

namespace App\Domain\Deployment;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;

class DeploymentConfig
{
    public bool $useBuildServer;

    public string $baseDir;

    public Server $server;

    public Application $application;

    // TODO: Set this variable.
    public int $customPort = 22;

    public SwarmDocker|StandaloneDocker|null $destination = null;

    // TODO: Improve setting
    public $commit;

    // TODO: Improve setting
    public Collection $coolifyVariables;

    // TODO: Set
    public ?ApplicationPreview $preview = null;

    public string $buildImageName;

    public string $productionImageName;
}
