<?php

use App\Jobs\SubscriptionInvoiceFailedJob;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\InvoiceService;
use Stripe\Service\SubscriptionService;
use Stripe\Stripe;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');

    // The failure email builds a portal session with the static Stripe API. Make it fail at once, without network.
    $this->apiBase = Stripe::$apiBase;
    Stripe::$apiBase = 'http://127.0.0.1:9';

    $this->team = Team::factory()->create();
    $this->subscription = Subscription::create([
        'team_id' => $this->team->id,
        'stripe_customer_id' => 'cus_failed',
        'stripe_subscription_id' => 'sub_new',
        'stripe_invoice_paid' => false,
    ]);

    $this->invoices = Mockery::mock(InvoiceService::class);
    $this->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->invoices = $this->invoices;
    $stripe->subscriptions = $this->subscriptions;
    $this->instance(StripeClient::class, $stripe);

    $this->subscriptions->shouldReceive('retrieve')->with('sub_new')->andReturn((object) ['status' => 'incomplete']);

    // A recent paid invoice of an older subscription of the same customer.
    $this->invoices->shouldReceive('all')->andReturnUsing(fn (array $parameters) => (object) [
        'data' => ($parameters['subscription'] ?? null) === null
            ? [(object) ['paid' => true, 'created' => time(), 'subscription' => 'sub_old']]
            : [],
    ]);
});

afterEach(function () {
    Stripe::$apiBase = $this->apiBase;
});

test('a paid invoice of another subscription does not mark the row as paid', function () {
    // The job goes on to the failure email, which needs the Stripe portal.
    expect(fn () => (new SubscriptionInvoiceFailedJob($this->team))->handle())->toThrow(ApiConnectionException::class);

    expect($this->subscription->fresh()->stripe_invoice_paid)->toBeFalsy();
});

test('a row without a subscription ID is not marked as paid by customer invoices', function () {
    $this->subscription->update(['stripe_subscription_id' => null]);

    expect(fn () => (new SubscriptionInvoiceFailedJob($this->team->fresh()))->handle())->toThrow(ApiConnectionException::class);

    expect($this->subscription->fresh()->stripe_invoice_paid)->toBeFalsy();
});
