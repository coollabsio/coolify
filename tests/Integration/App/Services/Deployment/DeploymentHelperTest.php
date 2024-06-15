<?php
namespace Tests\Integration\App\Services\Deployment;


use App\Domain\Remote\Commands\RemoteCommand;
use App\Exceptions\RemoteCommandInvalidException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;

beforeEach(function () {
    /** @var DeploymentProvider $deploymentProvider */
    $deploymentProvider =  $this->app->make(DeploymentProvider::class);
    $this->server = Server::factory()->create(['ip' => '127.0.0.1']);

    $this->deploymentHelper = $deploymentProvider->forServer($this->server);
    $this->savedOutputs = collect();
});


it('is able to return user home directory', function () {
    $result = $this->deploymentHelper->executeCommand('echo $HOME');
    expect($result)->toBe('/root');
});

it('is unable to execute commands as not every command is a RemoteCommand instance', function() {
    $application = Application::factory()->create();

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
        'application_id' => $application->id,
    ]);


    $this->deploymentHelper->executeAndSave([
       'ls -lah'
    ], $deploymentQueue, $this->savedOutputs);
})->expectException(RemoteCommandInvalidException::class);

it('is able to save commands executed to the deployment log', function() {
   $application = Application::factory()->create();

   $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
       'application_id' => $application->id,
   ]);

   $result = $this->deploymentHelper->executeAndSave([
       new RemoteCommand('ls -lah', save: 'debug'),
       new RemoteCommand('whoami', save: 'debug', append: true)
   ], $deploymentQueue, $this->savedOutputs);

   dd($this->savedOutputs);


});
