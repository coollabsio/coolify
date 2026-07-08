<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('cleanup:database prunes crash-log snapshots older than the keep-days window', function () {
    $stale = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'last_crash_logs' => ['app-container' => 'old crash'],
    ]);
    $stale->forceFill(['last_crash_logs_captured_at' => now()->subDays(90)])->saveQuietly();

    $fresh = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'last_crash_logs' => ['app-container' => 'recent crash'],
        'last_crash_logs_captured_at' => now()->subDays(1),
    ]);

    $this->artisan('cleanup:database', ['--yes' => true, '--keep-days' => 60])->assertExitCode(0);

    expect($stale->fresh()->last_crash_logs)->toBeNull();
    expect($stale->fresh()->last_crash_logs_captured_at)->toBeNull();

    expect($fresh->fresh()->last_crash_logs)->toBe(['app-container' => 'recent crash']);
});
