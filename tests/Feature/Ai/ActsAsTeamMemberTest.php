<?php

use App\Jobs\Ai\Concerns\ActsAsTeamMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function teamMemberRunner(): object
{
    return new class
    {
        use ActsAsTeamMember;

        public function act(int $userId, int $teamId): void
        {
            $this->actAsTeamMember($userId, $teamId);
        }

        public function clear(): void
        {
            $this->clearTeamMemberContext();
        }
    };
}

test('the assistant job authenticates the user and resolves the team for the MCP tools', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    $runner = teamMemberRunner();
    $runner->act($user->id, $team->id);

    expect(auth()->user()?->id)->toBe($user->id)
        ->and(currentTeam()?->id)->toBe($team->id);
});

test('the assistant job clears auth and team context so it does not leak to the next job', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    $runner = teamMemberRunner();
    $runner->act($user->id, $team->id);
    $runner->clear();

    expect(auth()->user())->toBeNull()
        ->and(currentTeam())->toBeNull();
});
