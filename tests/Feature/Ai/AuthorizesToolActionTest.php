<?php

use App\Ai\Concerns\AuthorizesToolAction;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAuthTraitHarness(): object
{
    return new class
    {
        use AuthorizesToolAction;

        public function callAuthorize(string $ability, $target): User
        {
            return $this->authorizeToolAction($ability, $target, 'harness');
        }

        public function callTeamId(): int
        {
            return $this->actingTeamId();
        }
    };
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

test('an admin is authorized against the target policy', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $harness = makeAuthTraitHarness();

    expect($harness->callAuthorize('delete', $this->server)->id)->toBe($this->admin->id)
        ->and($harness->callTeamId())->toBe($this->team->id);
});

test('a member is denied a destructive ability before anything runs', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $harness = makeAuthTraitHarness();

    expect(fn () => $harness->callAuthorize('delete', $this->server))
        ->toThrow(AuthorizationException::class);
});

test('an admin of another team cannot act on this team resource', function () {
    $otherTeam = Team::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherAdmin->teams()->attach($otherTeam, ['role' => 'admin']);
    $this->actingAs($otherAdmin);
    session(['currentTeam' => ['id' => $otherTeam->id]]);

    $harness = makeAuthTraitHarness();

    expect(fn () => $harness->callAuthorize('delete', $this->server))
        ->toThrow(AuthorizationException::class);
});
