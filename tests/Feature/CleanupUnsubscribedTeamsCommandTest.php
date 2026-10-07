<?php

use App\Models\Project;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
});

function endedSubscription(Team $team, array $attributes = []): Subscription
{
    return Subscription::factory()->create([
        'team_id' => $team->id,
        'updated_at' => now()->subMonths(7),
        ...$attributes,
    ]);
}

test('it refuses to run outside Coolify Cloud', function () {
    config()->set('constants.coolify.self_hosted', true);
    $team = Team::factory()->create();
    endedSubscription($team);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('This command can only be run on Coolify Cloud.')
        ->assertFailed();

    $this->assertModelExists($team);
});

test('it rejects a months value that is not a positive integer', function (string $months) {
    $team = Team::factory()->create();
    endedSubscription($team);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--months' => $months, '--yes' => true])
        ->expectsOutput('The --months option must be a positive integer.')
        ->assertFailed();

    $this->assertModelExists($team);
})->with(['0', '-1', 'abc']);

test('it previews eligible teams without deleting them', function () {
    $team = Team::factory()->create(['name' => 'Acme Corp']);
    endedSubscription($team);

    $this->artisan('cloud:cleanup-unsubscribed-teams')
        ->expectsOutputToContain('Found 1 team')
        ->expectsOutputToContain('Acme Corp')
        ->expectsOutput('Dry run only. Use --yes to delete eligible teams.')
        ->assertSuccessful();

    $this->assertModelExists($team);
});

test('it deletes an eligible team with its subscription, servers, projects, and orphaned owner', function () {
    $owner = User::factory()->create();
    $team = $owner->teams()->first();
    $subscription = endedSubscription($team);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $trashedServer = Server::factory()->create(['team_id' => $team->id]);
    $trashedServer->delete();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 1 team and 1 user.')
        ->assertSuccessful();

    $this->assertModelMissing($team);
    $this->assertModelMissing($subscription);
    $this->assertModelMissing($project);
    $this->assertModelMissing($owner);
    expect(Server::withTrashed()->whereKey([$server->id, $trashedServer->id])->exists())->toBeFalse();
});

test('it keeps members with another team and detaches them from the deleted team', function () {
    $team = Team::factory()->create();
    endedSubscription($team);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);
    $member->update(['current_team_id' => $team->id]);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();

    $this->assertModelMissing($team);
    $member->refresh();
    expect($member->current_team_id)->toBeNull()
        ->and($member->teams()->count())->toBe(1);
    $this->assertDatabaseMissing('team_user', ['team_id' => $team->id]);
});

test('it keeps teams that are not eligible', function (array $attributes) {
    $team = Team::factory()->create();
    endedSubscription($team, $attributes);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 0 teams and 0 users.')
        ->assertSuccessful();

    $this->assertModelExists($team);
})->with([
    'paid subscription' => [['stripe_invoice_paid' => true, 'stripe_subscription_id' => 'sub_paid']],
    'paused subscription that still exists in Stripe' => [['stripe_subscription_id' => 'sub_paused']],
    'subscription ended within the cutoff' => [['updated_at' => now()->subMonths(3)]],
    'subscription without a Stripe customer' => [['stripe_customer_id' => null]],
]);

test('it respects the months option', function () {
    $team = Team::factory()->create();
    endedSubscription($team, ['updated_at' => now()->subMonths(4)]);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();
    $this->assertModelExists($team);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--months' => 3, '--yes' => true])->assertSuccessful();
    $this->assertModelMissing($team);
});
