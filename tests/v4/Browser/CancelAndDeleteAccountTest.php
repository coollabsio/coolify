<?php

use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');
    InstanceSettings::forceCreate(['id' => 0, 'is_sponsorship_popup_enabled' => false]);
    $user = User::factory()->create([
        'name' => 'Paying User',
        'email' => 'paying@example.com',
        'password' => Hash::make('password'),
    ]);
    Subscription::create([
        'team_id' => $user->teams()->first()->id,
        'stripe_subscription_id' => 'sub_browser',
        'stripe_customer_id' => 'cus_browser',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_browser',
    ]);
    Team::query()->update(['show_boarding' => false]);
    Cache::flush();
    // SQLite returns stripe_invoice_paid as 1, which isSubscriptionActive() treats as unpaid.
    $this->withoutMiddleware(DecideWhatToDoWithUser::class);
});

it('offers account deletion when cancelling the subscription immediately', function () {
    $page = visit('/login')
        ->fill('email', 'paying@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertDontSee('These credentials do not match')
        ->navigate('/subscription')
        ->assertSee('Cancel subscription');

    $page->click('button:visible:has-text("Cancel Immediately")')
        ->assertSee('Delete my account.')
        ->screenshot(filename: 'cancel-delete-account-step1')
        ->click('label:has-text("Delete my account.")')
        ->click('button:visible:has-text("Continue")')
        ->assertSee('The following actions will be performed')
        ->screenshot(filename: 'cancel-delete-account-step2');
});

it('cancels, deletes the account, and lands on login without a follow-up request', function () {
    User::factory()->create(['id' => 0, 'email' => 'root@example.com']);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_browser');
    app()->instance(StripeClient::class, $stripe);

    $page = visit('/login')
        ->fill('email', 'paying@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertDontSee('These credentials do not match')
        ->navigate('/subscription')
        ->assertSee('Cancel subscription');

    $page->click('button:visible:has-text("Cancel Immediately")')
        ->click('label:has-text("Delete my account.")')
        ->click('button:visible:has-text("Continue")')
        ->fill('[x-model="userConfirmationText"]:visible', "Paying User's Team")
        ->click('button:visible:has-text("Permanently Cancel")')
        ->fill('[x-model="password"]:visible', 'password');

    // Count the Livewire update requests sent after the final confirmation.
    $page->script("sessionStorage.setItem('livewireUpdates', '0'); const originalFetch = window.fetch; window.fetch = (input, init) => { if (String(input?.url ?? input).includes('/update')) { sessionStorage.setItem('livewireUpdates', String(Number(sessionStorage.getItem('livewireUpdates')) + 1)); } return originalFetch(input, init); };");

    $page->click('button:visible:has-text("Confirm")')
        ->assertPathIs('/login')
        ->wait(1)
        ->screenshot(filename: 'cancel-delete-account-done');

    expect($page->script("sessionStorage.getItem('livewireUpdates')"))->toBe('1')
        ->and(User::query()->where('email', 'paying@example.com')->exists())->toBeFalse();
});
