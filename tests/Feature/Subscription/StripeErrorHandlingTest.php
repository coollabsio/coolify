<?php

use App\Jobs\StripeProcessJob;
use App\Jobs\SubscriptionInvoiceFailedJob;
use App\Jobs\VerifyStripeSubscriptionStatusJob;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');
    config()->set('subscription.stripe_excluded_plans', '');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
});

describe('StripeProcessJob re-throws exceptions for queue retry', function () {
    test('unhandled event type propagates exception instead of swallowing it', function () {
        $event = [
            'type' => 'some.unknown.event',
            'data' => ['object' => []],
        ];

        $job = new StripeProcessJob($event);

        expect(fn () => $job->handle())->toThrow(\RuntimeException::class, 'Unhandled event type: some.unknown.event');
    });

    test('missing subscription in invoice.paid propagates exception', function () {
        $event = [
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'customer' => 'cus_nonexistent',
                    'amount_paid' => 1000,
                    'subscription' => 'sub_123',
                    'lines' => ['data' => [['plan' => ['id' => 'price_test']]]],
                ],
            ],
        ];

        $job = new StripeProcessJob($event);

        expect(fn () => $job->handle())->toThrow(\RuntimeException::class, 'No subscription found for customer');
    });
});

describe('invoice.payment_failed dispatches with delay on Stripe API error', function () {
    test('Stripe API failure during payment intent verification dispatches delayed job', function () {
        Queue::fake();

        Subscription::create([
            'team_id' => $this->team->id,
            'stripe_subscription_id' => 'sub_test_verify',
            'stripe_customer_id' => 'cus_test_verify',
            'stripe_invoice_paid' => false,
        ]);

        $mockStripe = Mockery::mock(\Stripe\StripeClient::class);
        $mockPaymentIntents = Mockery::mock();
        $mockStripe->paymentIntents = $mockPaymentIntents;

        $mockPaymentIntents
            ->shouldReceive('retrieve')
            ->with('pi_test_123')
            ->andThrow(new \Exception('Stripe API unavailable'));

        app()->bind(\Stripe\StripeClient::class, fn () => $mockStripe);

        $event = [
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_test_verify',
                    'id' => 'inv_test',
                    'payment_intent' => 'pi_test_123',
                ],
            ],
        ];

        $job = new StripeProcessJob($event);
        $job->handle();

        Queue::assertPushed(SubscriptionInvoiceFailedJob::class, function ($job) {
            return $job->delay instanceof \DateTimeInterface;
        });
    });
});

describe('VerifyStripeSubscriptionStatusJob re-throws for queue retry', function () {
    test('Stripe API error propagates exception instead of swallowing it', function () {
        $subscription = Subscription::create([
            'team_id' => $this->team->id,
            'stripe_subscription_id' => 'sub_verify_rethrow',
            'stripe_customer_id' => 'cus_verify_rethrow',
            'stripe_invoice_paid' => true,
        ]);

        $job = new VerifyStripeSubscriptionStatusJob($subscription);

        expect(fn () => $job->handle())->toThrow(\Exception::class);
    });
});

describe('SubscriptionInvoiceFailedJob re-throws on verification failure', function () {
    test('Stripe API error during verification propagates instead of sending false email', function () {
        Subscription::create([
            'team_id' => $this->team->id,
            'stripe_subscription_id' => 'sub_inv_fail',
            'stripe_customer_id' => 'cus_inv_fail',
            'stripe_invoice_paid' => false,
        ]);

        $job = new SubscriptionInvoiceFailedJob($this->team);

        expect(fn () => $job->handle())->toThrow(\Exception::class);
    });

    test('has retry configuration', function () {
        $job = new SubscriptionInvoiceFailedJob($this->team);

        expect($job->tries)->toBe(3);
        expect($job->backoff)->toBe([30, 60, 120]);
    });
});
