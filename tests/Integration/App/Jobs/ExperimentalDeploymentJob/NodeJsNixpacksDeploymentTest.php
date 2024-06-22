<?php

use App\Jobs\ExperimentalDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;

it('should be able to deploy a Node.js Nixpacks project', function () {

    $server = Server::factory()->create();

    $application = Application::factory()->create([
        'name' => 'NodeJS Fastify Example',
        'fqdn' => 'http://nodejs-testing.127.0.0.1.sslip.io',
        'git_repository' => 'coollabsio/coolify-examples',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'base_directory' => '/nodejs',
    ]);

    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
    ]);

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

    config(['queue.default' => 'sync']);

   $job = new ExperimentalDeploymentJob($applicationDeploymentQueue->id);

    dispatch($job);




});
