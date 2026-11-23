<?php

use App\Actions\Stripe\FindOpenStripeSubscriptions;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Collection;
use Stripe\Service\CustomerService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

uses(RefreshDatabase::class);

function stripeList(array $objects): Collection
{
    return Collection::constructFrom(['object' => 'list', 'data' => $objects, 'has_more' => false]);
}

test('it finds the open subscriptions of every customer of the email and of the given customers', function () {
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->customers = Mockery::mock(CustomerService::class);
    $stripe->customers->shouldReceive('all')->with(['email' => 'Owner@Example.com', 'limit' => 100])->andReturn(stripeList([['object' => 'customer', 'id' => 'cus_a']]));
    $stripe->customers->shouldReceive('all')->with(['email' => 'owner@example.com', 'limit' => 100])->andReturn(stripeList([['object' => 'customer', 'id' => 'cus_b'], ['object' => 'customer', 'id' => 'cus_a']]));
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('all')->once()->with(['customer' => 'cus_given', 'limit' => 100])->andReturn(stripeList([['object' => 'subscription', 'id' => 'sub_given', 'status' => 'past_due']]));
    $stripe->subscriptions->shouldReceive('all')->once()->with(['customer' => 'cus_a', 'limit' => 100])->andReturn(stripeList([['object' => 'subscription', 'id' => 'sub_a', 'status' => 'active']]));
    $stripe->subscriptions->shouldReceive('all')->once()->with(['customer' => 'cus_b', 'limit' => 100])->andReturn(stripeList([
        ['object' => 'subscription', 'id' => 'sub_b', 'status' => 'unpaid'],
        ['object' => 'subscription', 'id' => 'sub_expired', 'status' => 'incomplete_expired'],
    ]));
    app()->instance(StripeClient::class, $stripe);

    $subscriptions = app(FindOpenStripeSubscriptions::class)->handle(['Owner@Example.com', null], ['cus_given', null]);

    expect($subscriptions->keys()->all())->toBe(['sub_given', 'sub_a', 'sub_b']);
});

test('the team of a subscription is the team that records it, else the team in its metadata', function () {
    $recordingTeam = Team::factory()->create();
    Subscription::create(['team_id' => $recordingTeam->id, 'stripe_subscription_id' => 'sub_recorded']);

    expect(FindOpenStripeSubscriptions::teamId(StripeSubscription::constructFrom(['id' => 'sub_recorded', 'metadata' => ['team_id' => '999']])))->toBe($recordingTeam->id)
        ->and(FindOpenStripeSubscriptions::teamId(StripeSubscription::constructFrom(['id' => 'sub_new', 'metadata' => ['team_id' => '42']])))->toBe(42)
        ->and(FindOpenStripeSubscriptions::teamId(StripeSubscription::constructFrom(['id' => 'sub_none'])))->toBeNull();
});
