<?php

use App\Livewire\Subscription\Index;
use App\Livewire\Subscription\PricingPlans;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
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
