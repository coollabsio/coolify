<?php

namespace App\Console\Commands;

use App\Domain\Deployment\DeploymentAction\DeployDockerfileAction;
use App\Domain\Deployment\DeploymentConfig;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;
use Illuminate\Console\Command;
use Visus\Cuid2\Cuid2;

class TesterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tester';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(DockerProvider $dockerProvider, DeploymentProvider $deploymentProvider)
    {
        $server = Server::find(0);
        $network = 'coolify';

        $application = Application::find(4);

        $applicationDeploymentQueue = new ApplicationDeploymentQueue();
        $applicationDeploymentQueue->deployment_uuid = (string) new Cuid2(7);
        $applicationDeploymentQueue->application_id = $application->id;
        $deploymentHelper = $deploymentProvider->forServer($server);


        $deployDockerFileAction = new DeployDockerfileAction($applicationDeploymentQueue, $server, $application, $deploymentHelper, $dockerProvider->forServer($server));

        $config = new DeploymentConfig();
        $config->baseDir = '/';
        $config->useBuildServer = false;

        $docker = new StandaloneDocker();



        $collect = collect();

        $deployDockerFileAction->prepare($config, $docker, $collect);
        //        $endA = microtime(true);

        //        $durationA = $endA - $startA;
        //        $durationB = $endB - $startB;
        //
        //        $this->info("Time taken for resultA: {$durationA} seconds");
        //        $this->info("Time taken for resultB: {$durationB} seconds");

        dd($resultA);
    }
}
