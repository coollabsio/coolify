<?php

use App\Jobs\Experimental\ExperimentalDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\GithubApp;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\User;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;
use Illuminate\Support\Facades\Http;

it('should be able to deploy a Simple Dockerfile project', function () {

    $dockerHostIp = gethostbyname('host.docker.internal');
    // TODO: Ensure that no mails are being send
    // TODO: Assert that these are faked and asserted
    // TODO: See also InstanceSettingsFactory
    $server = Server::factory()->create();

    $publicGitHub = GithubApp::factory()
        ->publicGitHub()
        ->create();

    $server->settings->is_reachable = true;
    $server->settings->is_usable = true;
    $server->settings->save();

    expect($server->isFunctional())
        ->toBeTrue('Server is not functional');

    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'coolify',
    ]);

    $domainNameInDocker = "http://dockerimage-nginx-testing.{$dockerHostIp}.sslip.io";

    $application = Application::factory()->create([
        'name' => 'Dockerimage Deployment',
        'fqdn' => "{$domainNameInDocker},http://dockerimage-nginx-testing.127.0.0.1.sslip.io",
        'git_repository' => 'coollabsio/coolify-examples',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'dockerfile' => null,
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'latest',
        'build_pack' => 'dockerimage',
        'ports_exposes' => '80',
        'destination_type' => StandaloneDocker::class,
        'destination_id' => $destination->id,
    ]);

    assertUrlStatus($domainNameInDocker, 404);

    // Add user to team, otherwise exception in app/Notifications/Channels/EmailChannel.php:18
    $user = User::factory()->create();

    $team = $application->environment->project->team;

    $user->teams()->attach($team, ['role' => 'admin']);

    $applicationDeploymentQueue = ApplicationDeploymentQueue::factory()
        ->create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'destination_id' => $destination->id,
            'force_rebuild' => true,
            'is_webhook' => false,
            'commit' => 'HEAD',
        ]);

    $job = new ExperimentalDeploymentJob($applicationDeploymentQueue->id);

    expect($job)->toBeInstanceOf(ExperimentalDeploymentJob::class);

    $dockerProvider = $this->app->make(DockerProvider::class);

    expect($dockerProvider)
        ->toBeInstanceOf(DockerProvider::class);

    $deploymentProvider = $this->app->make(DeploymentProvider::class);

    expect($deploymentProvider)
        ->toBeInstanceOf(DeploymentProvider::class);

    $job->handle($dockerProvider, $deploymentProvider);

    assertUrlStatus($domainNameInDocker, 200);

    $content = Http::get($domainNameInDocker)->body();
    expect($content)
        ->toContain('Welcome to nginx!')
        ->toContain('If you see this page, the nginx web server is successfully installed');
    // skip the test per default, but run it if a special env environment is set
})->skip(! getenv('RUN_EXPENSIVE_TESTS'), 'This test is expensive and should only be run in special environments');
