<?php

use App\Actions\Stripe\RefundSubscription;
use App\Livewire\Subscription\Actions;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_test_123',
        'stripe_customer_id' => 'cus_test_123',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_123',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('cancelImmediately with refund option', function () {
    test('refunds and cancels via RefundSubscription when refund checkbox is selected', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched('success')
            ->assertRedirect(route('subscription.index'));
    });

    test('dispatches error when refund fails', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => false, 'error' => 'No paid invoice found to refund.']);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched('error');
    });

    test('rejects invalid password before refunding', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldNotReceive('execute');
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'wrong-password', ['refundLatestPayment'])
            ->assertReturned('Invalid password.');
    });
});
