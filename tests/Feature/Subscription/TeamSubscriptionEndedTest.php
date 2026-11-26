<?php

use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('subscriptionEnded keeps servers usable until no row of the team pays', function () {
    Server::flushIdentityMap();
    $team = Team::factory()->create();
    $paying = Subscription::create(['team_id' => $team->id, 'stripe_subscription_id' => 'sub_paying', 'stripe_invoice_paid' => true]);
    $ending = Subscription::create(['team_id' => $team->id, 'stripe_subscription_id' => 'sub_ending', 'stripe_invoice_paid' => true]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update(['is_usable' => true]);

    $team->subscriptionEnded($ending);
    expect($server->settings()->first()->is_usable)->toBeTruthy();

    $team->subscriptionEnded($paying);
    expect($server->settings()->first()->is_usable)->toBeFalsy();
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
