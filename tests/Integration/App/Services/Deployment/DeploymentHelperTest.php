<?php

namespace Tests\Integration\App\Services\Deployment;

use App\Domain\Remote\Commands\RemoteCommand;
use App\Exceptions\RemoteCommandInvalidException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Services\Deployment\DeploymentProvider;

beforeEach(function () {
    /** @var DeploymentProvider $deploymentProvider */
    $deploymentProvider = $this->app->make(DeploymentProvider::class);
    $this->server = Server::factory()->create(['ip' => '127.0.0.1']);

    $this->deploymentHelper = $deploymentProvider->forServer($this->server);
    $this->savedOutputs = collect();
});

it('is able to return user home directory', function () {
    $result = $this->deploymentHelper->executeCommand('echo $HOME');
    expect($result->result)->toBe('/root');
});

it('is unable to execute commands as not every command is a RemoteCommand instance', function () {
    $application = Application::factory()->create();

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
        'application_id' => $application->id,
    ]);

    $this->deploymentHelper->executeAndSave([
        'ls -lah',
    ], $deploymentQueue, $this->savedOutputs);
})->expectException(RemoteCommandInvalidException::class);

it('is able to save commands executed to the deployment log', function () {
    $application = Application::factory()->create();

    $deploymentQueue = ApplicationDeploymentQueue::factory()->create([
        'application_id' => $application->id,
    ]);

    $this->deploymentHelper->executeAndSave([
        new RemoteCommand('ls -lah', save: 'debug'),
        new RemoteCommand('echo "This is a super secret command"', hidden: true),
        new RemoteCommand('echo "Hello from a test"', save: 'debug', append: true),
    ], $deploymentQueue, $this->savedOutputs);

    expect($this->savedOutputs)
        ->toHaveCount(1)
        ->toHaveKey('debug');

    $debugOutput = $this->savedOutputs->get('debug');

    expect($debugOutput)
        ->toContain('Hello from a test')
        ->toContain('tailwind.config.js');

    $deploymentQueue = $deploymentQueue->refresh();

    $deploymentLogs = $deploymentQueue->logs;

    $deploymentLogs = json_decode($deploymentLogs);

    expect($deploymentLogs)->toHaveCount(3);

    // Validate commands
    $firstCommand = $deploymentLogs[0];
    $secondCommand = $deploymentLogs[1];
    $thirdCommand = $deploymentLogs[2];

    /** @noinspection MultipleExpectChainableInspection */
    expect($firstCommand->command)
        ->toBe('ls -lah')
        ->and($firstCommand->output)
        ->toContain('tailwind.config.js')
        ->and($firstCommand->type)
        ->toBe('stdout')
        ->and($firstCommand->hidden)
        ->toBeFalse()
//        ->and($firstCommand->batch)
//        ->toBe(2)
        ->and($firstCommand->order)
        ->toBe(1);

    /** @noinspection MultipleExpectChainableInspection */
    expect($secondCommand->command)
        ->toBe('echo "This is a super secret command"')
        ->and($secondCommand->output)
        ->toBe('This is a super secret command')
        ->and($secondCommand->type)
        ->toBe('stdout')
        ->and($secondCommand->hidden)
        ->toBeTrue()
//        ->and($secondCommand->batch)
//        ->toBe(2)
        ->and($secondCommand->order)
        ->toBe(2);

    /** @noinspection MultipleExpectChainableInspection */
    expect($thirdCommand->command)
        ->toBe('echo "Hello from a test"')
        ->and($thirdCommand->output)
        ->toBe('Hello from a test')
        ->and($thirdCommand->type)
        ->toBe('stdout')
        ->and($thirdCommand->hidden)
        ->toBeFalse()
//        ->and($thirdCommand->batch)
//        ->toBe(2)
        ->and($thirdCommand->order)
        ->toBe(3);

});
