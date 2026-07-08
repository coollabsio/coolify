<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'last_crash_logs' => ['app-container' => "boom, it crashed\nexit code 137"],
        'last_crash_logs_captured_at' => now(),
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server->settings->fill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();
});

test('getLogs falls back to the crash-log snapshot when the live container returns nothing', function () {
    Process::fake();

    $server = Server::with('settings')->find($this->server->id);

    $component = Livewire::test(GetLogs::class, [
        'server' => $server,
        'resource' => $this->application,
        'container' => 'app-container',
    ])->call('getLogs');

    expect($component->get('outputs'))
        ->toContain('boom, it crashed')
        ->toContain('automatically stopped');
});
