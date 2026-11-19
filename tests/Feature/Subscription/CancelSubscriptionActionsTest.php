<?php

use App\Actions\Stripe\CancelSubscription;
use App\Actions\Stripe\CancelSubscriptionAtPeriodEnd;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Exception\InvalidRequestException;
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
            ->andThrow(new InvalidRequestException('Subscription not found'));

        $action = new CancelSubscriptionAtPeriodEnd($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Stripe error');
    });
});

describe('CancelSubscription::cancelById', function () {
    test('cancels an active subscription and ends the local row', function () {
        $this->mockSubscriptions->shouldReceive('retrieve')->with('sub_test_456')->andReturn(Stripe\Subscription::constructFrom(['id' => 'sub_test_456', 'status' => 'active']));
        $this->mockSubscriptions->shouldReceive('cancel')->once()->with('sub_test_456', []);
        app()->instance(StripeClient::class, $this->mockStripe);

        expect(CancelSubscription::cancelById('sub_test_456'))->toBeTrue()
            ->and($this->subscription->fresh()->stripe_subscription_id)->toBeNull()
            ->and($this->subscription->fresh()->ended_at)->not->toBeNull();
    });

    test('ends the local row without cancelling when Stripe already ended the subscription', function (string $status) {
        $this->mockSubscriptions->shouldReceive('retrieve')->with('sub_test_456')->andReturn(Stripe\Subscription::constructFrom(['id' => 'sub_test_456', 'status' => $status]));
        $this->mockSubscriptions->shouldNotReceive('cancel');
        app()->instance(StripeClient::class, $this->mockStripe);

        expect(CancelSubscription::cancelById('sub_test_456'))->toBeTrue()
            ->and($this->subscription->fresh()->stripe_subscription_id)->toBeNull()
            ->and($this->subscription->fresh()->ended_at)->not->toBeNull();
    })->with(['canceled', 'incomplete_expired']);

    test('ends the local row when Stripe no longer has the subscription', function () {
        $this->mockSubscriptions->shouldReceive('retrieve')->with('sub_test_456')->andThrow(
            InvalidRequestException::factory('No such subscription', 404, null, null, null, 'resource_missing')
        );
        $this->mockSubscriptions->shouldNotReceive('cancel');
        app()->instance(StripeClient::class, $this->mockStripe);

        expect(CancelSubscription::cancelById('sub_test_456'))->toBeTrue()
            ->and($this->subscription->fresh()->stripe_subscription_id)->toBeNull()
            ->and($this->subscription->fresh()->ended_at)->not->toBeNull();
    });

    test('keeps the local row when Stripe fails for another reason', function (Throwable $error) {
        $this->mockSubscriptions->shouldReceive('retrieve')->with('sub_test_456')->andThrow($error);
        app()->instance(StripeClient::class, $this->mockStripe);

        expect(CancelSubscription::cancelById('sub_test_456'))->toBeFalse()
            ->and($this->subscription->fresh()->stripe_subscription_id)->toBe('sub_test_456')
            ->and($this->subscription->fresh()->stripe_invoice_paid)->toBeTruthy();
    })->with([
        'invalid request' => fn () => InvalidRequestException::factory('Invalid API key', 400, null, null, null, 'api_key_expired'),
        'connection' => fn () => new RuntimeException('Stripe unavailable'),
    ]);
});
