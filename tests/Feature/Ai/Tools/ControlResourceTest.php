<?php

use App\Ai\Tools\ControlResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
});

test('control is a reversible tool and is not approval-gated', function () {
    $tool = new ControlResource;

    expect($tool)->toBeInstanceOf(Tool::class)
        ->and($tool)->not->toBeInstanceOf(Approvable::class);
});

test('control returns not-found for a uuid outside the current team', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $result = (string) (new ControlResource)->handle(new Request([
        'resource' => 'application',
        'action' => 'restart',
        'uuid' => 'does-not-exist',
    ]));

    expect($result)->toContain('not found');
});
