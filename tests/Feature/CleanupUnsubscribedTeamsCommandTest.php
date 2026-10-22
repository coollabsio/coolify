<?php

use App\Actions\Team\DeleteTeamData;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    Process::fake();
    fakeOpenStripeSubscriptions();
});

function endedSubscription(Team $team, array $attributes = []): Subscription
{
    return Subscription::factory()->create([
        'team_id' => $team->id,
        'ended_at' => now()->subDays(31),
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

test('it rejects a days value that is not a positive integer', function (string $days) {
    $team = Team::factory()->create();
    endedSubscription($team);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--days' => $days, '--yes' => true])
        ->expectsOutput('The --days option must be a positive integer.')
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

test('it deletes an eligible team with its subscription, servers, projects, and orphaned owner without running commands on the servers', function () {
    Storage::fake('images');
    $owner = User::factory()->create(['avatar_path' => 'avatars/owner.jpg', 'avatar_storage_type' => 'local']);
    Storage::disk('images')->put('avatars/owner.jpg', 'jpg');
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
    expect(Server::withTrashed()->whereKey([$server->id, $trashedServer->id])->exists())->toBeFalse()
        ->and(Storage::disk('images')->exists('avatars/owner.jpg'))->toBeFalse();
    Process::assertNothingRan();
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

test('it keeps teams that are not eligible', function (array $subscriptionRows) {
    $team = Team::factory()->create(['created_at' => now()->subYear()]);
    foreach ($subscriptionRows as $attributes) {
        endedSubscription($team, $attributes);
    }

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 0 teams and 0 users.')
        ->assertSuccessful();

    $this->assertModelExists($team);
})->with([
    'paid subscription' => [[['stripe_invoice_paid' => true, 'stripe_subscription_id' => 'sub_paid']]],
    'past due subscription' => [[['stripe_past_due' => true, 'stripe_subscription_id' => 'sub_due']]],
    'subscription ended within the grace period' => [[['ended_at' => now()->subDays(29)]]],
    'an old ended row and a current paying row' => [[['ended_at' => now()->subYear()], ['stripe_invoice_paid' => true, 'stripe_subscription_id' => 'sub_new']]],
    'an old ended row and a recently ended row' => [[['ended_at' => now()->subYear()], ['ended_at' => now()->subDays(5)]]],
]);

test('it deletes teams that stopped paying but keep a Stripe subscription id', function (array $attributes) {
    $team = Team::factory()->create();
    endedSubscription($team, ['stripe_subscription_id' => 'sub_kept', ...$attributes]);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 1 team and 0 users.')
        ->assertSuccessful();

    $this->assertModelMissing($team);
})->with([
    'refunded after a fraud warning' => [['stripe_refunded_at' => now()->subDays(40)]],
    'paused or incomplete_expired' => [['stripe_plan_id' => 'price_paused']],
]);

test('it starts the grace period when the subscription ended, not when the row was last updated', function () {
    $team = Team::factory()->create();
    $subscription = endedSubscription($team, ['ended_at' => now()->subDays(31)]);
    $subscription->update(['stripe_feedback' => 'too_expensive']);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();

    $this->assertModelMissing($team);
});

test('it respects the days option', function () {
    $team = Team::factory()->create();
    endedSubscription($team, ['ended_at' => now()->subDays(10)]);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();
    $this->assertModelExists($team);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--days' => 7, '--yes' => true])->assertSuccessful();
    $this->assertModelMissing($team);
});

test('it uses the configured retention period by default', function () {
    config()->set('constants.cloud.data_retention_days', 5);
    $team = Team::factory()->create();
    endedSubscription($team, ['ended_at' => now()->subDays(6)]);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();

    $this->assertModelMissing($team);
});

test('it deletes a never-paid team after the grace period together with its member', function (array $subscription) {
    $owner = User::factory()->create();
    $team = $owner->teams()->first();
    $team->forceFill(['created_at' => now()->subDays(31)])->save();
    if ($subscription !== []) {
        Subscription::factory()->create(['team_id' => $team->id, ...$subscription]);
    }

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 1 team and 1 user.')
        ->assertSuccessful();

    $this->assertModelMissing($team);
    $this->assertModelMissing($owner);
})->with([
    'without a subscription row' => [[]],
    'with a row without a Stripe customer' => [['stripe_customer_id' => null]],
]);

test('it keeps a never-paid team that is younger than the grace period', function () {
    $owner = User::factory()->create();
    $team = $owner->teams()->first();
    $team->forceFill(['created_at' => now()->subDays(29)])->save();

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();

    $this->assertModelExists($team);
    $this->assertModelExists($owner);
});

test('it keeps the never-paid personal team of a user who is a member of another team', function () {
    $user = User::factory()->create();
    $personalTeam = $user->teams()->first();
    $personalTeam->forceFill(['created_at' => now()->subYear()])->save();
    $payingTeam = Team::factory()->create(['created_at' => now()->subYear()]);
    Subscription::factory()->create(['team_id' => $payingTeam->id, 'stripe_invoice_paid' => true, 'stripe_subscription_id' => 'sub_paying']);
    $payingTeam->members()->attach($user, ['role' => 'member']);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 0 teams and 0 users.')
        ->assertSuccessful();

    $this->assertModelExists($personalTeam);
    $this->assertModelExists($user);
});

/**
 * A user whose personal team stopped paying, who is also a member of a paying team.
 *
 * @return array{user: User, personalTeam: Team, payingTeam: Team}
 */
function lapsedPersonalTeamOfPayingTeamMember(): array
{
    $user = User::factory()->create();
    $personalTeam = $user->teams()->first();
    $payingTeam = Team::factory()->create(['name' => 'Paying Team']);
    Subscription::factory()->create(['team_id' => $payingTeam->id, 'stripe_invoice_paid' => true, 'stripe_subscription_id' => 'sub_paying']);
    $payingTeam->members()->attach($user, ['role' => 'member']);
    $user->update(['current_team_id' => $personalTeam->id]);

    return ['user' => $user, 'personalTeam' => $personalTeam, 'payingTeam' => $payingTeam];
}

test('it keeps the lapsed personal team of a member of another team and removes only its data', function () {
    ['user' => $user, 'personalTeam' => $personalTeam, 'payingTeam' => $payingTeam] = lapsedPersonalTeamOfPayingTeamMember();
    $subscription = endedSubscription($personalTeam);
    $privateKey = PrivateKey::factory()->create(['team_id' => $personalTeam->id]);
    $server = Server::factory()->create(['team_id' => $personalTeam->id, 'private_key_id' => $privateKey->id]);
    $project = Project::factory()->create(['team_id' => $personalTeam->id]);
    $personalTeam->emailNotificationSettings()->update(['smtp_enabled' => true, 'smtp_from_address' => 'owner@example.com']);
    $personalTeam->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => encrypt('https://example.com/webhook')]);
    $payingServer = Server::factory()->create(['team_id' => $payingTeam->id]);
    $sessionId = Str::random(40);
    DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->artisan('cloud:cleanup-unsubscribed-teams')
        ->expectsTable(
            ['Team', 'Name', 'Action', 'Servers', 'Projects', 'Members', 'Subscription ended', 'Created'],
            [[$personalTeam->id, $personalTeam->name, 'remove data, keep team', 1, 1, 1, now()->subDays(31)->toDateString(), $personalTeam->created_at->toDateString()]],
        )
        ->assertSuccessful();

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 0 teams and 0 users.')
        ->expectsOutput('Removed the data of 1 personal team and kept the team.')
        ->assertSuccessful();

    $personalTeam->refresh();
    expect($personalTeam->personal_team)->toBeTrue()
        ->and($user->teams()->pluck('teams.id')->sort()->values()->all())->toBe(collect([$personalTeam->id, $payingTeam->id])->sort()->values()->all())
        ->and($user->fresh()->current_team_id)->toBe($personalTeam->id)
        ->and(DB::table('sessions')->where('id', $sessionId)->exists())->toBeTrue()
        ->and(Server::withTrashed()->whereKey($server->id)->exists())->toBeFalse()
        ->and($personalTeam->emailNotificationSettings->smtp_enabled)->toBeFalse()
        ->and($personalTeam->emailNotificationSettings->smtp_from_address)->toBeNull()
        ->and($personalTeam->webhookNotificationSettings->webhook_enabled)->toBeFalse()
        ->and($personalTeam->webhookNotificationSettings->webhook_url)->toBeNull();
    $this->assertModelMissing($subscription);
    $this->assertModelMissing($project);
    $this->assertModelMissing($privateKey);
    $this->assertModelExists($user);
    $this->assertModelExists($payingServer);
    expect($payingTeam->subscriptions()->count())->toBe(1);
});

test('it does not select a kept personal team again', function () {
    ['personalTeam' => $personalTeam] = lapsedPersonalTeamOfPayingTeamMember();
    $personalTeam->forceFill(['created_at' => now()->subYear()])->save();
    endedSubscription($personalTeam);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();

    $this->artisan('cloud:cleanup-unsubscribed-teams')
        ->expectsOutputToContain('Found 0 teams')
        ->assertSuccessful();
    $this->assertModelExists($personalTeam);
});

test('it deletes a lapsed team that is not personal even when its members have another team', function () {
    ['user' => $user, 'personalTeam' => $sharedTeam] = lapsedPersonalTeamOfPayingTeamMember();
    $sharedTeam->forceFill(['personal_team' => false])->save();
    endedSubscription($sharedTeam);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 1 team and 0 users.')
        ->assertSuccessful();

    $this->assertModelMissing($sharedTeam);
    $this->assertModelExists($user);
});

test('a team that fails to delete does not stop the others, and its users are deleted on a later run', function () {
    Notification::fake();
    $rootTeam = Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'created_at' => now()->subYear()]);
    $rootTeam->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    $failingOwner = User::factory()->create();
    $failingTeam = $failingOwner->teams()->first();
    endedSubscription($failingTeam);
    $otherTeam = Team::factory()->create();
    endedSubscription($otherTeam);

    $this->mock(DeleteTeamData::class, function ($mock) use ($failingTeam) {
        $mock->shouldReceive('handle')->andReturnUsing(function (Team $team) use ($failingTeam) {
            if ($team->id === $failingTeam->id) {
                throw new RuntimeException('S3 is down');
            }

            return (new DeleteTeamData)->handle($team);
        });
    });

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 1 team and 0 users.')
        ->expectsOutput('Could not delete 1 team. See the log for details.')
        ->assertFailed();

    $this->assertModelMissing($otherTeam);
    $this->assertModelExists($failingTeam);
    $this->assertModelExists($failingOwner);
    expect($failingOwner->teams()->whereKey($failingTeam->id)->exists())->toBeTrue();
    Notification::assertSentTo($rootTeam, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, "Could not delete 1 team:\n- team {$failingTeam->id}: S3 is down"));

    $this->forgetMock(DeleteTeamData::class);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 1 team and 1 user.')
        ->assertSuccessful();

    $this->assertModelMissing($failingTeam);
    $this->assertModelMissing($failingOwner);
    $this->assertModelExists($rootTeam);
});

test('it keeps a team while Stripe has a paying subscription of its owner that Coolify does not record', function (?string $metadataTeam) {
    Notification::fake();
    $rootTeam = Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'created_at' => now()->subYear()]);
    $rootTeam->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    $owner = User::factory()->create();
    $team = $owner->teams()->first();
    endedSubscription($team, ['stripe_customer_id' => 'cus_old']);
    $lookup = fakeOpenStripeSubscriptions([['id' => 'sub_orphan', 'status' => 'active', 'metadata' => $metadataTeam ? ['team_id' => $metadataTeam === 'this' ? (string) $team->id : '999999'] : []]]);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Skipped 1 team because of Stripe. See the log for details.')
        ->assertSuccessful();

    $this->assertModelExists($team);
    $this->assertModelExists($owner);
    $lookup->shouldHaveReceived('handle')->with(Mockery::on(fn ($emails) => $emails->all() === [$owner->email]), Mockery::on(fn ($customerIds) => $customerIds->all() === ['cus_old']));
    Notification::assertSentTo($rootTeam, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, "Skipped 1 team:\n- team {$team->id}: keep: Stripe subscription sub_orphan is active"));
})->with([
    'without a team' => [null],
    'of this team' => ['this'],
    'of a deleted team' => ['deleted'],
]);

test('it deletes a team when the paying Stripe subscription of its owner belongs to another team', function () {
    $owner = User::factory()->create();
    $team = $owner->teams()->first();
    endedSubscription($team);
    $payingTeam = Team::factory()->create();
    $payingTeam->members()->attach($owner->id, ['role' => 'owner']);
    Subscription::factory()->create(['team_id' => $payingTeam->id, 'stripe_subscription_id' => 'sub_paying', 'stripe_invoice_paid' => true]);
    fakeOpenStripeSubscriptions([
        ['id' => 'sub_paying', 'status' => 'active'],
        ['id' => 'sub_metadata', 'status' => 'active', 'metadata' => ['team_id' => (string) $payingTeam->id]],
        ['id' => 'sub_ended', 'status' => 'unpaid'],
    ]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('retrieve')->with('sub_ended')->andReturn(StripeSubscription::constructFrom(['id' => 'sub_ended', 'status' => 'unpaid']));
    $stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_ended', []);
    app()->instance(StripeClient::class, $stripe);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();

    $this->assertModelExists($payingTeam);
    expect(Subscription::where('team_id', $team->id)->exists())->toBeFalse();
});

test('it keeps a team when the Stripe check fails', function () {
    $team = Team::factory()->create();
    endedSubscription($team);
    fakeOpenStripeSubscriptions()->shouldReceive('handle')->andThrow(new RuntimeException('Stripe is down'));

    $this->artisan('cloud:cleanup-unsubscribed-teams')
        ->expectsTable(
            ['Team', 'Name', 'Action', 'Servers', 'Projects', 'Members', 'Subscription ended', 'Created'],
            [[$team->id, $team->name, 'keep: Stripe check failed (Stripe is down)', 0, 0, 0, now()->subDays(31)->toDateString(), $team->created_at->toDateString()]],
        )
        ->assertSuccessful();

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])->assertSuccessful();

    $this->assertModelExists($team);
});

test('it cancels the open Stripe subscriptions of a team that do not pay before it deletes the team', function () {
    $owner = User::factory()->create();
    $team = $owner->teams()->first();
    $row = endedSubscription($team, ['stripe_customer_id' => 'cus_unpaid', 'stripe_subscription_id' => 'sub_unpaid']);
    $otherTeam = Team::factory()->create();
    fakeOpenStripeSubscriptions([
        ['id' => 'sub_unpaid', 'status' => 'unpaid'],
        ['id' => 'sub_paused', 'status' => 'paused', 'metadata' => ['team_id' => (string) $team->id]],
        ['id' => 'sub_other_team', 'status' => 'unpaid', 'metadata' => ['team_id' => (string) $otherTeam->id]],
    ]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('retrieve')->andReturnUsing(fn (string $id) => StripeSubscription::constructFrom(['id' => $id, 'status' => 'unpaid']));
    $stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_unpaid', []);
    $stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_paused', []);
    $stripe->subscriptions->shouldNotReceive('cancel')->with('sub_other_team', []);
    app()->instance(StripeClient::class, $stripe);

    $this->artisan('cloud:cleanup-unsubscribed-teams')
        ->expectsTable(
            ['Team', 'Name', 'Action', 'Servers', 'Projects', 'Members', 'Subscription ended', 'Created'],
            [[$team->id, $team->name, 'delete team, cancel sub_unpaid, sub_paused', 0, 0, 1, now()->subDays(31)->toDateString(), $team->created_at->toDateString()]],
        )
        ->assertSuccessful();

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Deleted 1 team and 1 user.')
        ->assertSuccessful();

    $this->assertModelMissing($team);
    $this->assertModelMissing($row);
    $this->assertModelExists($otherTeam);
});

test('it keeps a team when Stripe does not cancel its open subscription', function () {
    Notification::fake();
    $rootTeam = Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'created_at' => now()->subYear()]);
    $rootTeam->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    $team = Team::factory()->create();
    endedSubscription($team, ['stripe_customer_id' => 'cus_unpaid']);
    fakeOpenStripeSubscriptions([['id' => 'sub_unpaid', 'status' => 'unpaid', 'metadata' => ['team_id' => (string) $team->id]]]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('retrieve')->andReturn(StripeSubscription::constructFrom(['id' => 'sub_unpaid', 'status' => 'unpaid']));
    $stripe->subscriptions->shouldReceive('cancel')->andThrow(new RuntimeException('Stripe is down'));
    app()->instance(StripeClient::class, $stripe);

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Could not delete 1 team. See the log for details.')
        ->assertFailed();

    $this->assertModelExists($team);
    Notification::assertSentToTimes($rootTeam, GeneralNotification::class, 1);
    Notification::assertSentTo($rootTeam, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, "- team {$team->id}: Could not cancel the Stripe subscription sub_unpaid."));
});

test('it sends one notification for all skipped teams', function () {
    Notification::fake();
    $rootTeam = Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'created_at' => now()->subYear()]);
    $rootTeam->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/webhook']);
    $teams = Team::factory()->count(22)->create();
    $teams->each(fn (Team $team) => endedSubscription($team));
    fakeOpenStripeSubscriptions()->shouldReceive('handle')->andThrow(new RuntimeException('Stripe is down'));

    $this->artisan('cloud:cleanup-unsubscribed-teams', ['--yes' => true])
        ->expectsOutput('Skipped 22 teams because of Stripe. See the log for details.')
        ->assertSuccessful();

    Notification::assertSentToTimes($rootTeam, GeneralNotification::class, 1);
    Notification::assertSentTo($rootTeam, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, 'Skipped 22 teams:')
        && str_contains($notification->message, '- and 2 more. See the log.'));
});
