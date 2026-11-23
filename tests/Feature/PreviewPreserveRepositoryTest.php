<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Process::fake(['*' => Process::result(output: '')]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

it('deletes the preview folders when the application configuration is deleted', function () {
    ApplicationPreview::create([
        'uuid' => 'preview-folder-cleanup-test',
        'application_id' => $this->application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/7',
    ])->delete();
    $mainDirectory = $this->application->workdir();

    $this->application->deleteConfigurations();

    Process::assertRan(fn ($process) => str_contains(
        (string) $process->input,
        'rm -rf '.escapeshellarg($mainDirectory).' '.escapeshellarg($mainDirectory.'-pr-7')
    ));
});
