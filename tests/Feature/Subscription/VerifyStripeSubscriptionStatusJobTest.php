<?php

use App\Jobs\VerifyStripeSubscriptionStatusJob;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->subscription = Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_verify_123',
        'stripe_customer_id' => 'cus_verify_123',
        'stripe_invoice_paid' => true,
        'stripe_past_due' => false,
    ]);
});

test('subscriptionEnded is called for unpaid status', function () {
    $mockStripe = Mockery::mock(StripeClient::class);
    $mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $mockStripe->subscriptions = $mockSubscriptions;

    $mockSubscriptions
        ->shouldReceive('retrieve')
        ->with('sub_verify_123')
        ->andReturn((object) [
            'status' => 'unpaid',
            'cancel_at_period_end' => false,
        ]);

    app()->bind(StripeClient::class, fn () => $mockStripe);

    // Create a server to verify it gets disabled
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    $job = new VerifyStripeSubscriptionStatusJob($this->subscription);
    $job->handle();

    $this->subscription->refresh();
    expect($this->subscription->stripe_invoice_paid)->toBeFalsy();
    expect($this->subscription->stripe_subscription_id)->toBeNull();
});

test('subscriptionEnded is called for incomplete_expired status', function () {
    $mockStripe = Mockery::mock(StripeClient::class);
    $mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $mockStripe->subscriptions = $mockSubscriptions;

    $mockSubscriptions
        ->shouldReceive('retrieve')
        ->with('sub_verify_123')
        ->andReturn((object) [
            'status' => 'incomplete_expired',
            'cancel_at_period_end' => false,
        ]);

    app()->bind(StripeClient::class, fn () => $mockStripe);

    $job = new VerifyStripeSubscriptionStatusJob($this->subscription);
    $job->handle();

    $this->subscription->refresh();
    expect($this->subscription->stripe_invoice_paid)->toBeFalsy();
    expect($this->subscription->stripe_subscription_id)->toBeNull();
});

test('subscriptionEnded is called for canceled status', function () {
    $mockStripe = Mockery::mock(StripeClient::class);
    $mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $mockStripe->subscriptions = $mockSubscriptions;

    $mockSubscriptions
        ->shouldReceive('retrieve')
        ->with('sub_verify_123')
        ->andReturn((object) [
            'status' => 'canceled',
            'cancel_at_period_end' => false,
        ]);

    app()->bind(StripeClient::class, fn () => $mockStripe);

    $job = new VerifyStripeSubscriptionStatusJob($this->subscription);
    $job->handle();

    $this->subscription->refresh();
    expect($this->subscription->stripe_invoice_paid)->toBeFalsy();
    expect($this->subscription->stripe_subscription_id)->toBeNull();
});
