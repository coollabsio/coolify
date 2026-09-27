<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    Storage::fake('ssh-keys');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('public-ssh-url-api-test', ['*'])->plainTextToken;
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('public application api converts scp-style ssh urls to https', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/applications/public', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'git_repository' => 'custom-user@git.example.com:2222/organization/repository.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'autogenerate_domain' => false,
    ]);

    $response->assertCreated();

    $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();

    expect($application->git_repository)->toBe('https://git.example.com/organization/repository.git');
});
