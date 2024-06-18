<?php

namespace App\Domain\Deployment;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use JetBrains\PhpStorm\ArrayShape;

class DeploymentContextCold
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

    // TODO: Set this variable
    public bool $forceRebuild;

    // TODO: Set this variable (is_this_additional_server)
    public bool $isAdditionalServer;

    // TODO: Set this variable
    public bool $isRestartOnly;

    // TODO: Set this variable.
    public string $configurationDir;

    #[ArrayShape(['useBuildServer' => 'bool', 'buildServer' => Server::class, 'originalServer' => Server::class])]
    public function getServerConfig(): array
    {
        // TODO: Fixme. Use Setter
    }
}
