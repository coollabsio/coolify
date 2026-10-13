<?php

use App\Livewire\Subscription\Actions;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Stripe\Service\SubscriptionService;
use Stripe\Stripe;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->originalStripeApiBase = Stripe::$apiBase;
    Stripe::$apiBase = 'http://127.0.0.1:1';

    $this->stripeResolved = false;
    $this->app->bind(StripeClient::class, function () {
        $this->stripeResolved = true;

        throw new RuntimeException('Stripe must not be called.');
    });

    $this->user = User::factory()->create();

    $this->ownTeam = Team::factory()->create();
    $this->ownTeam->members()->attach($this->user->id, ['role' => 'owner']);

    Subscription::create([
        'team_id' => $this->ownTeam->id,
        'stripe_subscription_id' => 'sub_own_team',
        'stripe_customer_id' => 'cus_own_team',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_123',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    $this->otherTeam = Team::factory()->create();
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'member']);

    $this->otherSubscription = Subscription::create([
        'team_id' => $this->otherTeam->id,
        'stripe_subscription_id' => 'sub_other_team',
        'stripe_customer_id' => 'cus_other_team',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_123',
        'stripe_cancel_at_period_end' => true,
        'stripe_past_due' => false,
    ]);

    $this->actingAs($this->user);
});

afterEach(function () {
    Stripe::$apiBase = $this->originalStripeApiBase;
});

dataset('subscription billing actions', [
    'updateQuantity' => [fn ($component) => $component->set('quantity', 5)->call('updateQuantity')],
    'loadPricePreview' => [fn ($component) => $component->call('loadPricePreview', 5)],
    'refundSubscription' => [fn ($component) => $component->call('refundSubscription', 'password')],
    'cancelImmediately' => [fn ($component) => $component->call('cancelImmediately', 'password')],
    'cancelImmediately with refund' => [fn ($component) => $component->call('cancelImmediately', 'password', ['refundLatestPayment'])],
    'cancelAtPeriodEnd' => [fn ($component) => $component->call('cancelAtPeriodEnd', 'password')],
    'resumeSubscription' => [fn ($component) => $component->call('resumeSubscription')],
    'loadRefundEligibility' => [fn ($component) => $component->call('loadRefundEligibility')],
    'stripeCustomerPortal' => [fn ($component) => $component->call('stripeCustomerPortal')],
]);

function expectOtherTeamSubscriptionUntouched(): void
{
    $subscription = test()->otherSubscription->fresh();

    expect($subscription->stripe_subscription_id)->toBe('sub_other_team')
        ->and((bool) $subscription->stripe_invoice_paid)->toBeTrue()
        ->and((bool) $subscription->stripe_cancel_at_period_end)->toBeTrue()
        ->and($subscription->stripe_refunded_at)->toBeNull()
        ->and(test()->stripeResolved)->toBeFalse();
}

test('a team member cannot mount the subscription actions', function () {
    session(['currentTeam' => $this->otherTeam]);

    Livewire::test(Actions::class)->assertForbidden();

    expectOtherTeamSubscriptionUntouched();
});

test('a team admin can mount the subscription actions and lock the team', function (string $role) {
    $this->otherTeam->members()->updateExistingPivot($this->user->id, ['role' => $role]);
    $this->user->unsetRelation('teams');
    session(['currentTeam' => $this->otherTeam]);

    Livewire::test(Actions::class)
        ->assertOk()
        ->assertSet('teamId', $this->otherTeam->id);
})->with(['admin', 'owner']);

test('an admin can still resume the subscription of the locked team', function () {
    $this->otherTeam->members()->updateExistingPivot($this->user->id, ['role' => 'admin']);
    $this->user->unsetRelation('teams');
    session(['currentTeam' => $this->otherTeam]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('update')->once()->with('sub_other_team', ['cancel_at_period_end' => false]);
    $this->instance(StripeClient::class, $stripe);

    Livewire::test(Actions::class)
        ->call('resumeSubscription')
        ->assertOk()
        ->assertDispatched('success');

    expect((bool) $this->otherSubscription->fresh()->stripe_cancel_at_period_end)->toBeFalse();
});

test('a billing action is rejected after the session switches to a team where the user is a member', function (Closure $action) {
    session(['currentTeam' => $this->ownTeam]);
    $component = Livewire::test(Actions::class)->assertOk();

    session(['currentTeam' => $this->otherTeam]);

    $action($component)->assertForbidden();

    expectOtherTeamSubscriptionUntouched();
})->with('subscription billing actions');

test('a billing action is rejected after the user is demoted to member of the locked team', function (Closure $action) {
    $this->otherTeam->members()->updateExistingPivot($this->user->id, ['role' => 'admin']);
    $this->user->unsetRelation('teams');
    session(['currentTeam' => $this->otherTeam]);
    $component = Livewire::test(Actions::class)->assertOk();

    $this->otherTeam->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->unsetRelation('teams');

    $action($component)->assertForbidden();

    expectOtherTeamSubscriptionUntouched();
})->with('subscription billing actions');

test('the locked team id cannot be changed by the client', function () {
    session(['currentTeam' => $this->ownTeam]);

    Livewire::test(Actions::class)->set('teamId', $this->otherTeam->id);
})->throws(CannotUpdateLockedPropertyException::class);
