<?php

use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_timezone' => 'UTC']);
    $this->rootTeam = Team::factory()->create(['id' => 0]);
    config(['constants.coolify.self_hosted' => false]);
    // Minute 0 is due for the Cloud connection check frequency (*/5).
    Carbon::setTestNow('2026-07-11 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function createServerForSelectionTest(Team $team): Server
{
    $privateKey = PrivateKey::query()->firstWhere('team_id', $team->id)
        ?? PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
}

/**
 * @return array<int, int> server id => number of connection checks queued for it
 */
function connectionChecksPerServer(): array
{
    Queue::fake();
    (new ServerManagerJob)->handle();

    return collect(Queue::pushed(ServerConnectionCheckJob::class))
        ->countBy(fn (ServerConnectionCheckJob $job) => $job->server->id)
        ->sortKeys()
        ->all();
}

it('processes each server of a paid team once, also with more than one paid subscription', function () {
    $team = Team::factory()->create();
    Subscription::factory()->count(2)->create(['team_id' => $team->id, 'stripe_invoice_paid' => true]);
    $server = createServerForSelectionTest($team);

    expect(connectionChecksPerServer())->toBe([$server->id => 1]);
});

it('skips servers of teams without a paid subscription', function () {
    $unpaid = Team::factory()->create();
    Subscription::factory()->create(['team_id' => $unpaid->id, 'stripe_invoice_paid' => false]);
    createServerForSelectionTest($unpaid);

    $pastDue = Team::factory()->create();
    Subscription::factory()->create(['team_id' => $pastDue->id, 'stripe_invoice_paid' => false, 'stripe_past_due' => true]);
    createServerForSelectionTest($pastDue);

    createServerForSelectionTest(Team::factory()->create());

    expect(connectionChecksPerServer())->toBe([]);
});

it('processes a team with one paid and one unpaid subscription', function () {
    $team = Team::factory()->create();
    Subscription::factory()->create(['team_id' => $team->id, 'stripe_invoice_paid' => false]);
    Subscription::factory()->create(['team_id' => $team->id, 'stripe_invoice_paid' => true]);
    $server = createServerForSelectionTest($team);

    expect(connectionChecksPerServer())->toBe([$server->id => 1]);
});

it('skips soft-deleted servers of a paid team', function () {
    $team = Team::factory()->create();
    Subscription::factory()->create(['team_id' => $team->id, 'stripe_invoice_paid' => true]);
    $kept = createServerForSelectionTest($team);
    createServerForSelectionTest($team)->delete();

    expect(connectionChecksPerServer())->toBe([$kept->id => 1]);
});

it('processes root team servers once without a subscription', function () {
    $rootServer = createServerForSelectionTest($this->rootTeam);

    expect(connectionChecksPerServer())->toBe([$rootServer->id => 1]);

    Subscription::factory()->create(['team_id' => 0, 'stripe_invoice_paid' => true]);
    Carbon::setTestNow('2026-07-11 12:05:00');

    expect(connectionChecksPerServer())->toBe([$rootServer->id => 1]);
});

it('follows subscription payment changes between runs', function () {
    $team = Team::factory()->create();
    $subscription = Subscription::factory()->create(['team_id' => $team->id, 'stripe_invoice_paid' => false]);
    $server = createServerForSelectionTest($team);

    expect(connectionChecksPerServer())->toBe([]);

    $subscription->update(['stripe_invoice_paid' => true]);
    Carbon::setTestNow('2026-07-11 12:05:00');
    expect(connectionChecksPerServer())->toBe([$server->id => 1]);

    $subscription->update(['stripe_invoice_paid' => false]);
    Carbon::setTestNow('2026-07-11 12:10:00');
    expect(connectionChecksPerServer())->toBe([]);
});

it('processes every server when they span more than one batch', function () {
    $team = Team::factory()->create();
    Subscription::factory()->create(['team_id' => $team->id, 'stripe_invoice_paid' => true]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $servers = Server::factory()
        ->count(ServerManagerJob::SERVER_BATCH_SIZE + 1)
        ->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);

    expect(connectionChecksPerServer())
        ->toBe($servers->pluck('id')->sort()->mapWithKeys(fn (int $id) => [$id => 1])->all());
});

it('processes every server in self-hosted mode', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow('2026-07-11 12:01:00');
    $server = createServerForSelectionTest(Team::factory()->create());

    expect(connectionChecksPerServer())->toBe([$server->id => 1]);
});
