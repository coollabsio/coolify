<?php

use App\Actions\Stripe\RefundSubscription;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Service\InvoiceService;
use Stripe\Service\RefundService;
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
        'stripe_subscription_id' => 'sub_test_123',
        'stripe_customer_id' => 'cus_test_123',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_123',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    $this->mockStripe = Mockery::mock(StripeClient::class);
    $this->mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $this->mockInvoices = Mockery::mock(InvoiceService::class);
    $this->mockRefunds = Mockery::mock(RefundService::class);

    $this->mockStripe->subscriptions = $this->mockSubscriptions;
    $this->mockStripe->invoices = $this->mockInvoices;
    $this->mockStripe->refunds = $this->mockRefunds;
});

describe('checkEligibility', function () {
    test('returns eligible when subscription is within 30 days', function () {
        $stripeSubscription = (object) [
            'status' => 'active',
            'start_date' => now()->subDays(10)->timestamp,
        ];

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_123')
            ->andReturn($stripeSubscription);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->checkEligibility($this->team);

        expect($result['eligible'])->toBeTrue();
        expect($result['days_remaining'])->toBe(20);
    });

    test('returns ineligible when subscription is past 30 days', function () {
        $stripeSubscription = (object) [
            'status' => 'active',
            'start_date' => now()->subDays(35)->timestamp,
        ];

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_123')
            ->andReturn($stripeSubscription);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->checkEligibility($this->team);

        expect($result['eligible'])->toBeFalse();
        expect($result['days_remaining'])->toBe(0);
        expect($result['reason'])->toContain('30-day refund window has expired');
    });

    test('returns ineligible when subscription is not active', function () {
        $stripeSubscription = (object) [
            'status' => 'canceled',
            'start_date' => now()->subDays(5)->timestamp,
        ];

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_123')
            ->andReturn($stripeSubscription);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->checkEligibility($this->team);

        expect($result['eligible'])->toBeFalse();
    });

    test('returns ineligible when no subscription exists', function () {
        $team = Team::factory()->create();

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->checkEligibility($team);

        expect($result['eligible'])->toBeFalse();
        expect($result['reason'])->toContain('No active subscription');
    });

    test('returns ineligible when invoice is not paid', function () {
        $this->subscription->update(['stripe_invoice_paid' => false]);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->checkEligibility($this->team);

        expect($result['eligible'])->toBeFalse();
        expect($result['reason'])->toContain('not paid');
    });

    test('returns ineligible when team has already been refunded', function () {
        $this->subscription->update(['stripe_refunded_at' => now()->subDays(60)]);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->checkEligibility($this->team);

        expect($result['eligible'])->toBeFalse();
        expect($result['reason'])->toContain('already been processed');
    });

    test('returns ineligible when stripe subscription not found', function () {
        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_123')
            ->andThrow(new \Stripe\Exception\InvalidRequestException('No such subscription'));

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->checkEligibility($this->team);

        expect($result['eligible'])->toBeFalse();
        expect($result['reason'])->toContain('not found in Stripe');
    });
});

describe('execute', function () {
    test('processes refund successfully', function () {
        $stripeSubscription = (object) [
            'status' => 'active',
            'start_date' => now()->subDays(10)->timestamp,
        ];

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_123')
            ->andReturn($stripeSubscription);

        $invoiceCollection = (object) ['data' => [
            (object) ['payment_intent' => 'pi_test_123'],
        ]];

        $this->mockInvoices
            ->shouldReceive('all')
            ->with([
                'subscription' => 'sub_test_123',
                'status' => 'paid',
                'limit' => 1,
            ])
            ->andReturn($invoiceCollection);

        $this->mockRefunds
            ->shouldReceive('create')
            ->with(['payment_intent' => 'pi_test_123'])
            ->andReturn((object) ['id' => 're_test_123']);

        $this->mockSubscriptions
            ->shouldReceive('cancel')
            ->with('sub_test_123')
            ->andReturn((object) ['status' => 'canceled']);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeTrue();
        expect($result['error'])->toBeNull();

        $this->subscription->refresh();
        expect($this->subscription->stripe_invoice_paid)->toBeFalsy();
        expect($this->subscription->stripe_feedback)->toBe('Refund requested by user');
        expect($this->subscription->stripe_refunded_at)->not->toBeNull();
    });

    test('prevents a second refund after re-subscribing', function () {
        $this->subscription->update([
            'stripe_refunded_at' => now()->subDays(15),
            'stripe_invoice_paid' => true,
            'stripe_subscription_id' => 'sub_test_new_456',
        ]);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('already been processed');
    });

    test('fails when no paid invoice found', function () {
        $stripeSubscription = (object) [
            'status' => 'active',
            'start_date' => now()->subDays(10)->timestamp,
        ];

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_123')
            ->andReturn($stripeSubscription);

        $invoiceCollection = (object) ['data' => []];

        $this->mockInvoices
            ->shouldReceive('all')
            ->andReturn($invoiceCollection);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('No paid invoice');
    });

    test('fails when invoice has no payment intent', function () {
        $stripeSubscription = (object) [
            'status' => 'active',
            'start_date' => now()->subDays(10)->timestamp,
        ];

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_123')
            ->andReturn($stripeSubscription);

        $invoiceCollection = (object) ['data' => [
            (object) ['payment_intent' => null],
        ]];

        $this->mockInvoices
            ->shouldReceive('all')
            ->andReturn($invoiceCollection);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('No payment intent');
    });

    test('fails when subscription is past refund window', function () {
        $stripeSubscription = (object) [
            'status' => 'active',
            'start_date' => now()->subDays(35)->timestamp,
        ];

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_123')
            ->andReturn($stripeSubscription);

        $action = new RefundSubscription($this->mockStripe);
        $result = $action->execute($this->team);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('30-day refund window');
    });
});
