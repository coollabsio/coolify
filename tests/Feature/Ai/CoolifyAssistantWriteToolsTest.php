<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Tools\ControlResource;
use App\Ai\Tools\CreateApplication;
use App\Ai\Tools\DeleteResource;
use App\Ai\Tools\DeleteServer;
use App\Ai\Tools\RunServerCommand;
use App\Ai\Tools\UpsertEnvironmentVariable;
use App\Mcp\Tools\CancelDeployment;
use App\Mcp\Tools\Control;
use App\Mcp\Tools\Deploy;
use App\Mcp\Tools\ListServers;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the assistant exposes native write tools and still excludes MCP mutating tools', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $admin->teams()->attach($team, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => ['id' => $team->id]]);

    $classes = array_map(fn ($t) => $t::class, (new CoolifyAssistant)->tools());

    expect($classes)
        ->toContain(ListServers::class)
        ->toContain(ControlResource::class)
        ->toContain(CreateApplication::class)
        ->toContain(DeleteServer::class)
        ->toContain(RunServerCommand::class)
        ->toContain(DeleteResource::class)
        ->toContain(UpsertEnvironmentVariable::class)
        ->not->toContain(Control::class)
        ->not->toContain(Deploy::class)
        ->not->toContain(CancelDeployment::class);
});

test('the instructions warn about destructive approvals', function () {
    expect(strtolower((string) (new CoolifyAssistant)->instructions()))
        ->toContain('approval');
});
