<?php

use App\Actions\Stripe\CreateCheckoutSession;
use App\Exceptions\CheckoutUnavailableException;
use App\Jobs\ServerLimitCheckJob;
use App\Livewire\Subscription\Index;
use App\Livewire\Subscription\PricingPlans;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\CustomerService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('subscription pricing page shows pay as you go plan for unsubscribed team admin', function () {
    Livewire::test(Index::class)
        ->assertSuccessful()
        ->assertSee('Pay as you go')
        ->assertSee('Subscribe monthly')
        ->assertSee('Choose a plan for Coolify Cloud');
});

test('subscription pricing page still renders when provider config is missing', function () {
    config()->set('subscription.provider', null);

    Livewire::test(Index::class)
        ->assertSuccessful()
        ->assertSee('Pay as you go')
        ->assertSee('Subscribe monthly');
});

test('pricing plans component renders subscribe actions', function () {
    Livewire::test(PricingPlans::class)
        ->assertSuccessful()
        ->assertSee('Pay as you go')
        ->assertSee('Subscribe monthly')
        ->assertSee('Subscribe yearly');
});

test('unsubscribed cloud sidebar does not expose global search', function () {
    $response = $this->get(route('subscription.index'));

    $response->assertSuccessful();
    // Search button is gated; global-search listener markup may still be present.
    // Search button is gated; OS-aware title uses Alpine :title, so assert on the trigger instead.
    $response->assertDontSee("\$dispatch('open-global-search')", false);
    $response->assertDontSee('>Search</span>', false);
});

test('subscribed cloud sidebar shows subscription link for team admins', function () {
    Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_active',
        'stripe_customer_id' => 'cus_active',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_active',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    Once::flush();
    session(['currentTeam' => $this->team->fresh()]);

    $html = view('components.navbar')->render();

    expect($html)
        ->toContain('title="Subscription"')
        ->toContain(route('subscription.show'));
});

test('subscription adjustment modal is protected from livewire morphing', function () {
    $view = file_get_contents(resource_path('views/livewire/subscription/actions.blade.php'));

    expect($view)
        ->toContain('<div wire:init="loadRefundEligibility" class="application-settings-workspace flex flex-col gap-6" x-data="{')
        ->toContain('<template x-teleport="body" wire:ignore>');
});

test('subscription pricing page does not render a single-item pricing tab strip', function () {
    $html = view('components.dashboard.navbar', [
        'section' => 'subscription',
        'title' => 'Subscription',
        'subtitle' => 'Choose a plan',
    ])->render();

    expect($html)
        ->toContain('Subscription')
        ->toContain('Choose a plan')
        ->not->toContain('app-tab')
        ->and($html)->not->toContain(route('subscription.index'));
});

test('subscription plan page does not render a single-item plan tab strip', function () {
    Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_active',
        'stripe_customer_id' => 'cus_active',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_active',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    // Refresh memoized subscription helpers after creating the row.
    Once::flush();
    session(['currentTeam' => $this->team->fresh()]);

    $html = view('components.dashboard.navbar', [
        'section' => 'subscription',
        'title' => 'Subscription',
        'subtitle' => 'Plan and billing',
    ])->render();

    expect($html)
        ->toContain('Subscription')
        ->toContain('Plan and billing')
        ->not->toContain('app-tab')
        ->and($html)->not->toContain(route('subscription.show'))
        ->and($html)->not->toContain(route('subscription.index'));
});

test('Stripe API failures show an error without redirecting or retrying checkout', function () {
    config()->set('subscription.stripe_price_id_dynamic_monthly', 'price_monthly');
    $this->mock(CreateCheckoutSession::class)
        ->shouldReceive('execute')->once()
        ->andThrow(new ApiConnectionException('Connection failed'));

    Livewire::test(PricingPlans::class)
        ->call('subscribeStripe', 'dynamic-monthly')
        ->assertDispatched('error', 'Unable to confirm checkout with Stripe. Please try again shortly.')
        ->assertNoRedirect();
});

test('expected checkout unavailable errors keep their user-facing messages', function (string $message) {
    config()->set('subscription.stripe_price_id_dynamic_monthly', 'price_monthly');
    Exceptions::fake();
    $this->mock(CreateCheckoutSession::class)
        ->shouldReceive('execute')->once()
        ->andThrow(new CheckoutUnavailableException($message));

    Livewire::test(PricingPlans::class)
        ->call('subscribeStripe', 'dynamic-monthly')
        ->assertDispatched('error', $message)
        ->assertNoRedirect();

    Exceptions::assertNothingReported();
})->with([
    'lock' => 'A subscription checkout is already being created for this team.',
    'active' => 'Team already has an active subscription.',
    'past_due' => "This team's subscription payment is past due. Update the payment method or settle the outstanding invoice in the billing portal.",
    'incomplete' => "This team's subscription payment is incomplete. Complete the payment in the billing portal.",
    'unpaid' => "This team's subscription is unpaid. Settle the outstanding invoice in the billing portal.",
]);

test('pricing plans links recoverable checkout blocks to the billing portal', function () {
    config()->set('subscription.stripe_price_id_dynamic_monthly', 'price_monthly');
    $message = "This team's subscription payment is past due. Update the payment method or settle the outstanding invoice in the billing portal.";
    $this->mock(CreateCheckoutSession::class)
        ->shouldReceive('execute')->once()
        ->andThrow(new CheckoutUnavailableException($message, 'https://billing.stripe.test/session'));

    Livewire::test(PricingPlans::class)
        ->call('subscribeStripe', 'dynamic-monthly')
        ->assertDispatched(
            'error',
            $message.' <a href="https://billing.stripe.test/session" target="_blank" rel="noopener noreferrer" class="underline">Open billing portal</a>'
        )
        ->assertNoRedirect();
});

test('unexpected checkout RuntimeExceptions are reported without exposing internal messages', function () {
    config()->set('subscription.stripe_price_id_dynamic_monthly', 'price_monthly');
    Exceptions::fake();
    $this->mock(CreateCheckoutSession::class)
        ->shouldReceive('execute')->once()
        ->andThrow(new RuntimeException('SQLSTATE[HY000]: General error: 1 table subscriptions has no column named foo'));

    Livewire::test(PricingPlans::class)
        ->call('subscribeStripe', 'dynamic-monthly')
        ->assertDispatched('error', 'Unable to start checkout. Please try again shortly.')
        ->assertNoRedirect();

    Exceptions::assertReported(RuntimeException::class);
});

test('subscription status check restores an active subscription after a missed webhook', function (int $quantity, int $expectedLimit, string $lookupKey) {
    Queue::fake();
    $this->team->update(['custom_server_limit' => 7]);
    $subscription = Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_recover', 'stripe_invoice_paid' => false]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->customers = Mockery::mock(CustomerService::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->customers->shouldReceive('retrieve')->with('cus_recover')->once()->andReturn((object) ['id' => 'cus_recover']);
    $stripe->subscriptions->shouldReceive('all')->with(['customer' => 'cus_recover'])->once()->andReturn((object) ['data' => [(object) [
        'id' => 'sub_recovered', 'status' => 'active', 'metadata' => (object) ['team_id' => (string) $this->team->id],
        'cancel_at_period_end' => true, 'items' => (object) ['data' => [(object) ['quantity' => $quantity, 'price' => (object) ['id' => 'price_yearly', 'lookup_key' => $lookupKey]]]],
    ]]]);
    app()->instance(StripeClient::class, $stripe);

    Livewire::test(Index::class)->call('getStripeStatus')->assertRedirect(route('subscription.show'));

    expect((bool) $subscription->fresh()->stripe_invoice_paid)->toBeTrue()
        ->and($subscription->fresh()->stripe_subscription_id)->toBe('sub_recovered')
        ->and($subscription->fresh()->stripe_plan_id)->toBe('price_yearly')
        ->and((bool) $subscription->fresh()->stripe_cancel_at_period_end)->toBeTrue()
        ->and((bool) $subscription->fresh()->stripe_past_due)->toBeFalse()
        ->and($this->team->fresh()->custom_server_limit)->toBe($expectedLimit);
    if (str_contains($lookupKey, 'dynamic')) {
        Queue::assertPushed(ServerLimitCheckJob::class);
    } else {
        Queue::assertNotPushed(ServerLimitCheckJob::class);
    }
})->with([[5, 5, 'dynamic_yearly'], [1, 2, 'dynamic_yearly'], [101, 100, 'dynamic_yearly'], [5, 7, 'legacy']]);

test('subscription status check does not activate non-active or other-team subscriptions', function (string $status, bool $otherTeam) {
    $subscription = Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_recover', 'stripe_invoice_paid' => false]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->customers = Mockery::mock(CustomerService::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->customers->shouldReceive('retrieve')->once()->andReturn((object) ['id' => 'cus_recover']);
    $stripe->subscriptions->shouldReceive('all')->once()->andReturn((object) ['data' => [(object) [
        'id' => 'sub_other', 'status' => $status, 'metadata' => (object) ['team_id' => (string) ($otherTeam ? $this->team->id + 100 : $this->team->id)],
    ]]]);
    app()->instance(StripeClient::class, $stripe);

    Livewire::test(Index::class)->call('getStripeStatus')->assertNoRedirect();
    expect((bool) $subscription->fresh()->stripe_invoice_paid)->toBeFalse();
    if ($otherTeam) {
        expect($subscription->fresh()->stripe_subscription_id)->toBeNull();
    }
})->with([['incomplete', false], ['unpaid', false], ['canceled', false], ['active', true]]);

test('team members cannot synchronize billing status', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->unsetRelation('teams');
    Livewire::test(Index::class)->call('getStripeStatus')->assertForbidden();
});
