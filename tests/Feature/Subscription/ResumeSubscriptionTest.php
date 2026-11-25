<?php

use App\Actions\Stripe\ResumeSubscription;
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
        'stripe_subscription_id' => 'sub_test_789',
        'stripe_customer_id' => 'cus_test_789',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_789',
        'stripe_cancel_at_period_end' => true,
        'stripe_past_due' => false,
    ]);

    $this->mockStripe = Mockery::mock(StripeClient::class);
    $this->mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $this->mockStripe->subscriptions = $this->mockSubscriptions;
});

describe('ResumeSubscription', function () {
    test('resumes subscription successfully', function () {
        $this->mockSubscriptions
            ->shouldReceive('update')
            ->with('sub_test_789', ['cancel_at_period_end' => false])
            ->andReturn((object) ['status' => 'active', 'cancel_at_period_end' => false]);

        $action = new ResumeSubscription($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeTrue();
        expect($result['error'])->toBeNull();

        $this->subscription->refresh();
        expect($this->subscription->stripe_cancel_at_period_end)->toBeFalsy();
    });

    test('fails when no subscription exists', function () {
        $team = Team::factory()->create();

        $action = new ResumeSubscription($this->mockStripe);
        $result = $action->execute($team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('No active subscription');
    });

    test('fails when subscription is not set to cancel', function () {
        $this->subscription->update(['stripe_cancel_at_period_end' => false]);

        $action = new ResumeSubscription($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('not set to cancel');
    });

    test('handles stripe API error gracefully', function () {
        $this->mockSubscriptions
            ->shouldReceive('update')
            ->andThrow(new \Stripe\Exception\InvalidRequestException('Subscription not found'));

        $action = new ResumeSubscription($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Stripe error');
    });
});
