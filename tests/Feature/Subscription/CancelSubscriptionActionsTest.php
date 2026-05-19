<?php

use App\Actions\Stripe\CancelSubscriptionAtPeriodEnd;
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
        'stripe_subscription_id' => 'sub_test_456',
        'stripe_customer_id' => 'cus_test_456',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_456',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    $this->mockStripe = Mockery::mock(StripeClient::class);
    $this->mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $this->mockStripe->subscriptions = $this->mockSubscriptions;
});

describe('CancelSubscriptionAtPeriodEnd', function () {
    test('cancels subscription at period end successfully', function () {
        $this->mockSubscriptions
            ->shouldReceive('update')
            ->with('sub_test_456', ['cancel_at_period_end' => true])
            ->andReturn((object) ['status' => 'active', 'cancel_at_period_end' => true]);

        $action = new CancelSubscriptionAtPeriodEnd($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeTrue();
        expect($result['error'])->toBeNull();

        $this->subscription->refresh();
        expect($this->subscription->stripe_cancel_at_period_end)->toBeTruthy();
        expect($this->subscription->stripe_invoice_paid)->toBeTruthy();
    });

    test('fails when no subscription exists', function () {
        $team = Team::factory()->create();

        $action = new CancelSubscriptionAtPeriodEnd($this->mockStripe);
        $result = $action->execute($team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('No active subscription');
    });

    test('fails when subscription is not active', function () {
        $this->subscription->update(['stripe_invoice_paid' => false]);

        $action = new CancelSubscriptionAtPeriodEnd($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('not active');
    });

    test('fails when already set to cancel at period end', function () {
        $this->subscription->update(['stripe_cancel_at_period_end' => true]);

        $action = new CancelSubscriptionAtPeriodEnd($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('already set to cancel');
    });

    test('handles stripe API error gracefully', function () {
        $this->mockSubscriptions
            ->shouldReceive('update')
            ->andThrow(new \Stripe\Exception\InvalidRequestException('Subscription not found'));

        $action = new CancelSubscriptionAtPeriodEnd($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Stripe error');
    });
});
