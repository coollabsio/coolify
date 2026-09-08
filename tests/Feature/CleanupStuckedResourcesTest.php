<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('deletes scheduled tasks whose configured owner no longer exists', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Queue::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $validTask = ScheduledTask::factory()->create([
        'team_id' => $team->id,
        'application_id' => $application->id,
    ]);
    $orphanedTask = ScheduledTask::factory()->create([
        'team_id' => $team->id,
        'application_id' => PHP_INT_MAX,
    ]);

    $this->artisan('cleanup:stucked-resources')->assertSuccessful();

    expect($validTask->fresh())->not->toBeNull()
        ->and($orphanedTask->fresh())->toBeNull();
});
