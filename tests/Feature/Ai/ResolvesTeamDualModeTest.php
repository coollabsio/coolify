<?php

use App\Mcp\Tools\GetCurrentTeam;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request as McpRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
});

test('a read tool resolves the team from the session when there is no token', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $response = app(GetCurrentTeam::class)->handle(new McpRequest([]));

    expect($response->isError())->toBeFalse();
});

test('a read tool denies a session team the user does not belong to', function () {
    $stranger = Team::factory()->create();
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $stranger->id]]);

    $response = app(GetCurrentTeam::class)->handle(new McpRequest([]));

    expect($response->isError())->toBeTrue();
});
