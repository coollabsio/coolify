<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('merges accepted keys with current storage without restoring removed seeds', function (string $current, string $expected) {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $seed = 'seed.example ssh-ed25519 AAAA';
    $accepted = 'new.example ssh-ed25519 BBBB';
    $application = Application::withoutEvents(fn () => Application::forceCreate(['environment_id' => $environment->id, 'uuid' => 'host-keys-test', 'git_repository' => 'git@example.com:repo', 'git_branch' => 'main', 'build_pack' => 'dockerfile', 'name' => 'host keys', 'git_ssh_known_hosts' => $seed]));
    Application::query()->whereKey($application->id)->update(['git_ssh_known_hosts' => $current]);
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->zeroOrMoreTimes();
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $outputs = collect();
    foreach (['application' => $application, 'application_deployment_queue' => $queue, 'deployment_uuid' => 'test', 'saved_outputs' => $outputs] as $name => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $name))->setValue($job, $value);
    }
    $job->shouldReceive('execute_remote_command')->once()->andReturnUsing(function () use ($outputs, $seed, $accepted) {
        $outputs->put('git_ssh_known_hosts', "$seed\n$accepted");
    });

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'save_git_ssh_known_hosts'))->invoke($job);

    expect($application->fresh()->git_ssh_known_hosts)->toBe($expected);
    expect($application->isDirty('git_ssh_known_hosts'))->toBeFalse();
})->with([
    'concurrent addition' => ['other.example ssh-ed25519 CCCC', "other.example ssh-ed25519 CCCC\nnew.example ssh-ed25519 BBBB"],
    'already accepted' => ['new.example ssh-ed25519 BBBB', 'new.example ssh-ed25519 BBBB'],
]);
