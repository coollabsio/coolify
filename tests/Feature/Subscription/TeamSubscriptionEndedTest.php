<?php

use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('subscriptionEnded does not throw when team has no subscription', function () {
    $team = Team::factory()->create();

    // Should return early without error — no NPE
    $team->subscriptionEnded();

    // If we reach here, no exception was thrown
    expect(true)->toBeTrue();
});

test('subscriptionEnded updates the exact subscription when duplicate rows exist', function () {
    $team = Team::factory()->create();
    $otherSubscription = Subscription::create([
        'team_id' => $team->id,
        'stripe_subscription_id' => 'sub_other',
        'stripe_invoice_paid' => true,
    ]);
    $subscriptionToEnd = Subscription::create([
        'team_id' => $team->id,
        'stripe_subscription_id' => 'sub_stale',
        'stripe_invoice_paid' => true,
    ]);

    $team->subscriptionEnded($subscriptionToEnd);

    expect($subscriptionToEnd->fresh()->stripe_subscription_id)->toBeNull()
        ->and($subscriptionToEnd->fresh()->stripe_invoice_paid)->toBeFalsy()
        ->and($otherSubscription->fresh()->stripe_subscription_id)->toBe('sub_other')
        ->and($otherSubscription->fresh()->stripe_invoice_paid)->toBeTruthy();
});
