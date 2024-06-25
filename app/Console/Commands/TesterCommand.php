<?php

namespace App\Console\Commands;

use App\Domain\Deployment\DeploymentContextCold;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\Experimental\ExperimentalDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;
use Illuminate\Console\Command;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class TesterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tester {--old} {--queue}';

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
        //        $server = Server::find(0);
        //        $network = 'coolify';
        //
        //        $application = Application::find(4);
        //
        //        $applicationDeploymentQueue = new ApplicationDeploymentQueue();
        //        $applicationDeploymentQueue->deployment_uuid = (string) new Cuid2(7);
        //        $applicationDeploymentQueue->application_id = $application->id;
        //        $deploymentHelper = $deploymentProvider->forServer($server);
        //
        //        $deployDockerFileAction = new DeployDockerfileAction($applicationDeploymentQueue, $server, $application, $deploymentHelper, $dockerProvider->forServer($server));
        //
        //        $config = new DeploymentContextCold();
        //        $config->baseDir = '/';
        //        $config->useBuildServer = false;
        //
        //        $docker = new StandaloneDocker();
        //
        //        $collect = collect();
        //
        //        $deployDockerFileAction->prepare($config, $docker, $collect);
        //        //        $endA = microtime(true);
        //
        //        //        $durationA = $endA - $startA;
        //        //        $durationB = $endB - $startB;
        //        //
        //        //        $this->info("Time taken for resultA: {$durationA} seconds");
        //        //        $this->info("Time taken for resultB: {$durationB} seconds");
        //
        //        dd($resultA);

        $application = Application::find(7);

        $deployment_uuid = (string) new Cuid2(7);
        $server = Server::find(0);

        $pull_request_id = 0;

        $force_rebuild = true;

        $deployment_link = Url::fromString($application->link()."/deployment/{$deployment_uuid}");
        $deployment_url = $deployment_link->getPath();
        $server_id = $application->destination->server->id;
        $server_name = $application->destination->server->name;
        $destination_id = $application->destination->id;

        if ($server) {
            $server_id = $server->id;
            $server_name = $server->name;
        }
        //        if ($destination) {
        //            $destination_id = $destination->id;
        //        }
        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $server_id,
            'server_name' => $server_name,
            'destination_id' => $destination_id,
            'deployment_uuid' => $deployment_uuid,
            'deployment_url' => $deployment_url,
            'pull_request_id' => $pull_request_id,
            'force_rebuild' => true,
            'is_webhook' => false,
            'restart_only' => false,
            'commit' => 'HEAD',
            'rollback' => false,
            'git_type' => null,
            'only_this_server' => false,
        ]);

        $queue = $this->option('queue');
        if (! $queue) {
            config(['queue.default' => 'sync']);
        }
        config(['logging.default' => 'errorlog']);

        $old = $this->option('old');
        if (! $old) {
            $job = new ExperimentalDeploymentJob($deployment->id);
        } else {
            $job = new ApplicationDeploymentJob($deployment->id);
        }

        dispatch($job);

    }
}
