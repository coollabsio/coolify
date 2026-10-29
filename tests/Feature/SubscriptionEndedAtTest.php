<?php

use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

test('ending a subscription sets ended_at once and later ends do not move it', function () {
    $team = Team::factory()->create();
    $subscription = Subscription::factory()->create([
        'team_id' => $team->id,
        'stripe_subscription_id' => 'sub_1',
        'stripe_invoice_paid' => true,
    ]);
    expect($subscription->ended_at)->toBeNull();

    Carbon::setTestNow('2026-01-10 12:00:00');
    $team->subscriptionEnded($subscription);
    expect($subscription->fresh()->ended_at->toDateTimeString())->toBe('2026-01-10 12:00:00');

    Carbon::setTestNow('2026-02-20 12:00:00');
    $team->fresh()->subscriptionEnded($subscription->fresh());
    $subscription->fresh()->update(['stripe_customer_id' => 'cus_new']);

    expect($subscription->fresh()->ended_at->toDateTimeString())->toBe('2026-01-10 12:00:00');
});

test('paying again clears ended_at', function () {
    $subscription = Subscription::factory()->create(['ended_at' => now()->subDays(40)]);

    $subscription->update(['stripe_invoice_paid' => true]);

    expect($subscription->fresh()->ended_at)->toBeNull();
});

test('a past due subscription in dunning does not start the retention clock', function () {
    $subscription = Subscription::factory()->create(['stripe_invoice_paid' => true]);

    $subscription->update(['stripe_past_due' => true]);
    expect($subscription->fresh()->ended_at)->toBeNull();

    $subscription->update(['stripe_invoice_paid' => false]);
    expect($subscription->fresh()->ended_at)->toBeNull();
});

test('a checkout that was started but never paid starts the retention clock', function () {
    Carbon::setTestNow('2026-03-01 08:00:00');
    $team = Team::factory()->create();

    $subscription = Subscription::query()->firstOrNew(['team_id' => $team->id]);
    $subscription->stripe_customer_id = 'cus_checkout';
    $subscription->save();

    expect($subscription->fresh()->ended_at->toDateTimeString())->toBe('2026-03-01 08:00:00');
});

test('the migration backfills ended_at from updated_at for rows that are not paying', function () {
    $migration = require database_path('migrations/2026_10_19_101103_add_ended_at_to_subscriptions_table.php');
    $migration->down();

    $team = Team::factory()->create();
    $row = fn (array $flags, string $updatedAt) => DB::table('subscriptions')->insertGetId([
        'team_id' => $team->id,
        'stripe_invoice_paid' => false,
        'stripe_past_due' => false,
        ...$flags,
        'created_at' => '2025-01-01 00:00:00',
        'updated_at' => $updatedAt,
    ]);
    $ended = $row([], '2025-05-05 05:05:05');
    $paid = $row(['stripe_invoice_paid' => true], '2025-06-06 06:06:06');
    $pastDue = $row(['stripe_past_due' => true], '2025-07-07 07:07:07');

    $migration->up();

    expect(DB::table('subscriptions')->where('id', $ended)->value('ended_at'))->toBe('2025-05-05 05:05:05')
        ->and(DB::table('subscriptions')->where('id', $paid)->value('ended_at'))->toBeNull()
        ->and(DB::table('subscriptions')->where('id', $pastDue)->value('ended_at'))->toBeNull();
});
