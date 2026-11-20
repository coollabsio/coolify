<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Tools\ListGithubApps as NativeListGithubApps;
use App\Mcp\Tools\CancelDeployment;
use App\Mcp\Tools\Control;
use App\Mcp\Tools\Deploy;
use App\Mcp\Tools\GetCurrentTeam;
use App\Mcp\Tools\GetEnvValues;
use App\Mcp\Tools\ListGithubApps;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ManageApplication;
use App\Mcp\Tools\ManageServer;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
});

test('the assistant exposes only read-only MCP tools', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $classes = array_map(fn ($t) => $t::class, (new CoolifyAssistant)->tools());

    expect($classes)->toContain(ListServers::class)
        ->toContain(GetCurrentTeam::class)
        ->not->toContain(Control::class)
        ->not->toContain(Deploy::class)
        ->not->toContain(CancelDeployment::class)
        ->not->toContain(ManageApplication::class)
        ->not->toContain(ManageServer::class)
        ->not->toContain(GetEnvValues::class);
});

test('a native catalog tool replaces the MCP tool with the same name', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $classes = array_map(fn ($t) => $t::class, (new CoolifyAssistant)->tools());

    expect($classes)->toContain(NativeListGithubApps::class)
        ->not->toContain(ListGithubApps::class);
});

test('the assistant gets no MCP read tools without an acting user', function () {
    $classes = array_map(fn ($t) => $t::class, (new CoolifyAssistant)->tools());

    expect($classes)->not->toContain(ListServers::class)
        ->not->toContain(GetCurrentTeam::class);
});

test('the assistant has non-empty instructions', function () {
    expect((string) (new CoolifyAssistant)->instructions())->not->toBeEmpty();
});
