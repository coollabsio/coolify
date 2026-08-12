<?php

use App\Actions\Stripe\SyncStripeSubscriptions;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Collection as StripeCollection;
use Stripe\Service\CustomerService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');

    $this->team = Team::factory()->create();
    $this->subscription = Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_stale',
        'stripe_customer_id' => 'cus_stale',
        'stripe_invoice_paid' => true,
    ]);
});

afterEach(function () {
    SyncStripeSubscriptions::clearFake();
});

function stripeSubscriptionCollection(array $subscriptions = []): StripeCollection
{
    return StripeCollection::constructFrom([
        'data' => $subscriptions,
        'has_more' => false,
        'url' => '/v1/subscriptions',
    ]);
}

test('fix mode fully ends a locally active subscription with an invalid Stripe status', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'unreachable_count' => 0,
        'unreachable_notification_sent' => false,
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $subscriptions = Mockery::mock(SubscriptionService::class);
    $customers = Mockery::mock(CustomerService::class);
    $stripe->subscriptions = $subscriptions;
    $stripe->customers = $customers;

    $subscriptions->shouldReceive('all')->twice()->andReturn(stripeSubscriptionCollection());
    $subscriptions->shouldReceive('retrieve')->with('sub_stale')->andReturn((object) [
        'status' => 'unpaid',
        'customer' => 'cus_stale',
    ]);
    $customers->shouldReceive('retrieve')->with('cus_stale')->andThrow(new RuntimeException('Customer unavailable'));

    $this->instance(StripeClient::class, $stripe);

    $progress = [];

    SyncStripeSubscriptions::run(
        fix: true,
        onProgress: function (string $stage, int $current, ?int $total) use (&$progress): void {
            $progress[] = [$stage, $current, $total];
        },
    );

    $this->subscription->refresh();
    $server->refresh();

    expect($this->subscription->stripe_invoice_paid)->toBeFalsy()
        ->and($this->subscription->stripe_subscription_id)->toBeNull()
        ->and($server->unreachable_count)->toBe(3)
        ->and($server->unreachable_notification_sent)->toBeTrue()
        ->and($progress)->toContain(['checking', 0, 1])
        ->and($progress)->toContain(['checking', 1, 1]);
});

test('a same-email subscription not linked to the team is left for manual review', function () {
    $stripe = Mockery::mock(StripeClient::class);
    $subscriptions = Mockery::mock(SubscriptionService::class);
    $customers = Mockery::mock(CustomerService::class);
    $stripe->subscriptions = $subscriptions;
    $stripe->customers = $customers;

    $subscriptions->shouldReceive('all')
        ->with(Mockery::on(fn (array $parameters) => isset($parameters['status'])))
        ->twice()
        ->andReturn(stripeSubscriptionCollection());
    $subscriptions->shouldReceive('retrieve')->with('sub_stale')->andReturn((object) [
        'status' => 'canceled',
        'customer' => 'cus_stale',
    ]);
    $customers->shouldReceive('retrieve')->with('cus_stale')->andReturn((object) [
        'email' => 'customer@example.com',
    ]);
    $customers->shouldReceive('all')->andReturn((object) [
        'data' => [(object) ['id' => 'cus_active']],
    ]);
    $subscriptions->shouldReceive('all')->with([
        'customer' => 'cus_active',
        'limit' => 10,
    ])->andReturn((object) [
        'data' => [(object) [
            'id' => 'sub_active_elsewhere',
            'status' => 'active',
        ]],
    ]);

    $this->instance(StripeClient::class, $stripe);

    $result = SyncStripeSubscriptions::run(fix: true);

    $this->subscription->refresh();

    expect($result['resubscribed'])->toHaveCount(1)
        ->and($result['discrepancies'])->toHaveCount(1)
        ->and($result['discrepancies'][0]['resolution'])->toBe('manual_review')
        ->and($this->subscription->stripe_invoice_paid)->toBeTruthy()
        ->and($this->subscription->stripe_subscription_id)->toBe('sub_stale');
});

test('a stale local subscription is deleted when its team has a valid replacement', function () {
    $replacement = Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_active_elsewhere',
        'stripe_customer_id' => 'cus_active',
        'stripe_invoice_paid' => true,
    ]);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $subscriptions = Mockery::mock(SubscriptionService::class);
    $customers = Mockery::mock(CustomerService::class);
    $stripe->subscriptions = $subscriptions;
    $stripe->customers = $customers;

    $subscriptions->shouldReceive('all')
        ->with(['status' => 'active', 'limit' => 100])
        ->andReturn(stripeSubscriptionCollection([
            ['id' => 'sub_active_elsewhere'],
        ]));
    $subscriptions->shouldReceive('all')
        ->with(['status' => 'past_due', 'limit' => 100])
        ->andReturn(stripeSubscriptionCollection());
    $subscriptions->shouldReceive('retrieve')->with('sub_stale')->andReturn((object) [
        'status' => 'canceled',
        'customer' => 'cus_stale',
    ]);
    $customers->shouldReceive('retrieve')->with('cus_stale')->andReturn((object) [
        'email' => 'customer@example.com',
    ]);
    $customers->shouldReceive('all')->andReturn((object) [
        'data' => [(object) ['id' => 'cus_active']],
    ]);
    $subscriptions->shouldReceive('all')->with([
        'customer' => 'cus_active',
        'limit' => 10,
    ])->andReturn((object) [
        'data' => [(object) [
            'id' => 'sub_active_elsewhere',
            'status' => 'active',
        ]],
    ]);

    $this->instance(StripeClient::class, $stripe);

    $result = SyncStripeSubscriptions::run(fix: true);

    expect($result['discrepancies'][0]['resolution'])->toBe('delete_stale')
        ->and($this->subscription->fresh())->toBeNull()
        ->and($replacement->fresh())->not->toBeNull()
        ->and($replacement->fresh()->stripe_invoice_paid)->toBeTruthy()
        ->and($server->settings->fresh()->is_reachable)->toBeTruthy()
        ->and($server->settings->fresh()->is_usable)->toBeTruthy();
});

test('a stale duplicate is deleted when its team already has an inactive subscription row', function () {
    $inactiveSubscription = Subscription::create([
        'team_id' => $this->team->id,
        'stripe_customer_id' => 'cus_stale',
        'stripe_invoice_paid' => false,
    ]);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $subscriptions = Mockery::mock(SubscriptionService::class);
    $customers = Mockery::mock(CustomerService::class);
    $stripe->subscriptions = $subscriptions;
    $stripe->customers = $customers;

    $subscriptions->shouldReceive('all')->twice()->andReturn(stripeSubscriptionCollection());
    $subscriptions->shouldReceive('retrieve')->with('sub_stale')->andReturn((object) [
        'status' => 'canceled',
        'customer' => 'cus_stale',
    ]);
    $customers->shouldReceive('retrieve')->with('cus_stale')->andThrow(new RuntimeException('Customer unavailable'));

    $this->instance(StripeClient::class, $stripe);

    $result = SyncStripeSubscriptions::run(fix: true);

    expect($result['discrepancies'][0]['resolution'])->toBe('delete_stale')
        ->and($this->subscription->fresh())->toBeNull()
        ->and($inactiveSubscription->fresh())->not->toBeNull()
        ->and($server->settings->fresh()->is_reachable)->toBeFalsy()
        ->and($server->settings->fresh()->is_usable)->toBeFalsy();
});

test('valid Stripe subscriptions remain active', function (string $status) {
    $stripe = Mockery::mock(StripeClient::class);
    $subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions = $subscriptions;

    $subscriptions->shouldReceive('all')
        ->with(['status' => $status, 'limit' => 100])
        ->andReturn(stripeSubscriptionCollection([
            ['id' => 'sub_stale'],
        ]));
    $subscriptions->shouldReceive('all')
        ->with(['status' => $status === 'active' ? 'past_due' : 'active', 'limit' => 100])
        ->andReturn(stripeSubscriptionCollection());
    $subscriptions->shouldNotReceive('retrieve');

    $this->instance(StripeClient::class, $stripe);

    $result = SyncStripeSubscriptions::run(fix: true);

    $this->subscription->refresh();

    expect($result['discrepancies'])->toBeEmpty()
        ->and($this->subscription->stripe_invoice_paid)->toBeTruthy()
        ->and($this->subscription->stripe_subscription_id)->toBe('sub_stale');
})->with(['active', 'past_due']);

test('the terminal command runs the reconciliation action synchronously', function () {
    SyncStripeSubscriptions::shouldRun()
        ->once()
        ->withArgs(fn (bool $fix, ?Closure $onProgress) => $fix === false && $onProgress instanceof Closure)
        ->andReturnUsing(function (bool $fix, Closure $onProgress): array {
            $onProgress('checking', 2, 10);

            return [
                'total_checked' => 0,
                'discrepancies' => [],
                'resubscribed' => [],
                'errors' => [],
                'fixed' => false,
            ];
        });

    $this->artisan('cloud:sync-stripe-subscriptions')
        ->expectsOutputToContain('Checking stale subscriptions against Stripe... 2/10')
        ->expectsOutput('Total subscriptions checked: 0')
        ->assertSuccessful();
});

test('the terminal command passes the fix option to the reconciliation action', function () {
    SyncStripeSubscriptions::shouldRun()
        ->once()
        ->withArgs(fn (bool $fix, ?Closure $onProgress) => $fix === true && $onProgress instanceof Closure)
        ->andReturn([
            'total_checked' => 1,
            'discrepancies' => [[
                'subscription_id' => 1,
                'team_id' => 2,
                'stripe_subscription_id' => 'sub_stale',
                'stripe_status' => 'canceled',
                'resolution' => 'manual_review',
            ]],
            'resubscribed' => [],
            'errors' => [],
            'fixed' => true,
            'fixed_count' => 0,
            'manual_review_count' => 1,
        ]);

    $this->artisan('cloud:sync-stripe-subscriptions', ['--fix' => true])
        ->expectsOutput('Total subscriptions checked: 1')
        ->expectsOutput('    Resolution: Manual review required')
        ->expectsOutput('Automatic corrections applied: 0')
        ->expectsOutput('Skipped for manual review: 1')
        ->assertSuccessful();
});
