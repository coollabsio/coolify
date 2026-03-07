<?php

use App\Actions\Stripe\UpdateSubscriptionQuantity;
use App\Jobs\ServerLimitCheckJob;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Stripe\Service\InvoiceService;
use Stripe\Service\SubscriptionService;
use Stripe\Service\TaxRateService;
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
        'stripe_subscription_id' => 'sub_test_qty',
        'stripe_customer_id' => 'cus_test_qty',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_qty',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    $this->mockStripe = Mockery::mock(StripeClient::class);
    $this->mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $this->mockInvoices = Mockery::mock(InvoiceService::class);
    $this->mockTaxRates = Mockery::mock(TaxRateService::class);
    $this->mockStripe->subscriptions = $this->mockSubscriptions;
    $this->mockStripe->invoices = $this->mockInvoices;
    $this->mockStripe->taxRates = $this->mockTaxRates;

    $this->stripeSubscriptionResponse = (object) [
        'items' => (object) [
            'data' => [(object) [
                'id' => 'si_item_123',
                'quantity' => 2,
                'price' => (object) ['unit_amount' => 500, 'currency' => 'usd'],
            ]],
        ],
    ];
});

describe('UpdateSubscriptionQuantity::execute', function () {
    test('updates quantity successfully', function () {
        Queue::fake();

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_qty')
            ->andReturn($this->stripeSubscriptionResponse);

        $this->mockSubscriptions
            ->shouldReceive('update')
            ->with('sub_test_qty', [
                'items' => [
                    ['id' => 'si_item_123', 'quantity' => 5],
                ],
                'proration_behavior' => 'always_invoice',
                'expand' => ['latest_invoice'],
            ])
            ->andReturn((object) [
                'status' => 'active',
                'latest_invoice' => (object) ['status' => 'paid'],
            ]);

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->execute($this->team, 5);

        expect($result['success'])->toBeTrue();
        expect($result['error'])->toBeNull();

        $this->team->refresh();
        expect($this->team->custom_server_limit)->toBe(5);

        Queue::assertPushed(ServerLimitCheckJob::class, function ($job) {
            return $job->team->id === $this->team->id;
        });
    });

    test('reverts subscription and voids invoice when payment fails', function () {
        Queue::fake();

        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_qty')
            ->andReturn($this->stripeSubscriptionResponse);

        // First update: changes quantity but payment fails
        $this->mockSubscriptions
            ->shouldReceive('update')
            ->with('sub_test_qty', [
                'items' => [
                    ['id' => 'si_item_123', 'quantity' => 5],
                ],
                'proration_behavior' => 'always_invoice',
                'expand' => ['latest_invoice'],
            ])
            ->andReturn((object) [
                'status' => 'active',
                'latest_invoice' => (object) ['id' => 'in_failed_123', 'status' => 'open'],
            ]);

        // Revert: restores original quantity
        $this->mockSubscriptions
            ->shouldReceive('update')
            ->with('sub_test_qty', [
                'items' => [
                    ['id' => 'si_item_123', 'quantity' => 2],
                ],
                'proration_behavior' => 'none',
            ])
            ->andReturn((object) ['status' => 'active']);

        // Void the unpaid invoice
        $this->mockInvoices
            ->shouldReceive('voidInvoice')
            ->with('in_failed_123')
            ->once();

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->execute($this->team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Payment failed');

        $this->team->refresh();
        expect($this->team->custom_server_limit)->not->toBe(5);

        Queue::assertNotPushed(ServerLimitCheckJob::class);
    });

    test('rejects quantity below minimum of 2', function () {
        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->execute($this->team, 1);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Minimum server limit is 2');
    });

    test('fails when no subscription exists', function () {
        $team = Team::factory()->create();

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->execute($team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('No active subscription');
    });

    test('fails when subscription is not active', function () {
        $this->subscription->update(['stripe_invoice_paid' => false]);

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->execute($this->team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('not active');
    });

    test('fails when subscription item cannot be found', function () {
        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_qty')
            ->andReturn((object) [
                'items' => (object) ['data' => []],
            ]);

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->execute($this->team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Could not find subscription item');
    });

    test('handles stripe API error gracefully', function () {
        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->andThrow(new \Stripe\Exception\InvalidRequestException('Subscription not found'));

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->execute($this->team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Stripe error');
    });

    test('handles generic exception gracefully', function () {
        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->andThrow(new \RuntimeException('Network error'));

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->execute($this->team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('unexpected error');
    });
});

describe('UpdateSubscriptionQuantity::fetchPricePreview', function () {
    test('returns full preview with proration and recurring cost with tax', function () {
        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_qty')
            ->andReturn($this->stripeSubscriptionResponse);

        $this->mockInvoices
            ->shouldReceive('upcoming')
            ->with([
                'customer' => 'cus_test_qty',
                'subscription' => 'sub_test_qty',
                'subscription_items' => [
                    ['id' => 'si_item_123', 'quantity' => 3],
                ],
                'subscription_proration_behavior' => 'create_prorations',
            ])
            ->andReturn((object) [
                'amount_due' => 2540,
                'total' => 2540,
                'subtotal' => 2000,
                'tax' => 540,
                'currency' => 'usd',
                'lines' => (object) [
                    'data' => [
                        (object) ['amount' => -300, 'proration' => true],  // credit for unused
                        (object) ['amount' => 800, 'proration' => true],   // charge for new qty
                        (object) ['amount' => 1500, 'proration' => false], // next cycle
                    ],
                ],
                'total_tax_amounts' => [
                    (object) ['tax_rate' => 'txr_123'],
                ],
            ]);

        $this->mockTaxRates
            ->shouldReceive('retrieve')
            ->with('txr_123')
            ->andReturn((object) [
                'display_name' => 'VAT',
                'jurisdiction' => 'HU',
                'percentage' => 27,
            ]);

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->fetchPricePreview($this->team, 3);

        expect($result['success'])->toBeTrue();
        // Due now: invoice total (2540) - recurring total (1905) = 635
        expect($result['preview']['due_now'])->toBe(635);
        // Recurring: 3 × $5.00 = $15.00
        expect($result['preview']['recurring_subtotal'])->toBe(1500);
        // Tax: $15.00 × 27% = $4.05
        expect($result['preview']['recurring_tax'])->toBe(405);
        // Total: $15.00 + $4.05 = $19.05
        expect($result['preview']['recurring_total'])->toBe(1905);
        expect($result['preview']['unit_price'])->toBe(500);
        expect($result['preview']['tax_description'])->toContain('VAT');
        expect($result['preview']['tax_description'])->toContain('27%');
        expect($result['preview']['quantity'])->toBe(3);
        expect($result['preview']['currency'])->toBe('USD');
    });

    test('returns preview without tax when no tax applies', function () {
        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_qty')
            ->andReturn($this->stripeSubscriptionResponse);

        $this->mockInvoices
            ->shouldReceive('upcoming')
            ->andReturn((object) [
                'amount_due' => 1250,
                'total' => 1250,
                'subtotal' => 1250,
                'tax' => 0,
                'currency' => 'usd',
                'lines' => (object) [
                    'data' => [
                        (object) ['amount' => 250, 'proration' => true],   // proration charge
                        (object) ['amount' => 1000, 'proration' => false], // next cycle
                    ],
                ],
                'total_tax_amounts' => [],
            ]);

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->fetchPricePreview($this->team, 2);

        expect($result['success'])->toBeTrue();
        // Due now: invoice total (1250) - recurring total (1000) = 250
        expect($result['preview']['due_now'])->toBe(250);
        // 2 × $5.00 = $10.00, no tax
        expect($result['preview']['recurring_subtotal'])->toBe(1000);
        expect($result['preview']['recurring_tax'])->toBe(0);
        expect($result['preview']['recurring_total'])->toBe(1000);
        expect($result['preview']['tax_description'])->toBeNull();
    });

    test('fails when no subscription exists', function () {
        $team = Team::factory()->create();

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->fetchPricePreview($team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['preview'])->toBeNull();
    });

    test('fails when subscription item not found', function () {
        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->with('sub_test_qty')
            ->andReturn((object) [
                'items' => (object) ['data' => []],
            ]);

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->fetchPricePreview($this->team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Could not retrieve subscription details');
    });

    test('handles Stripe API error gracefully', function () {
        $this->mockSubscriptions
            ->shouldReceive('retrieve')
            ->andThrow(new \RuntimeException('API error'));

        $action = new UpdateSubscriptionQuantity($this->mockStripe);
        $result = $action->fetchPricePreview($this->team, 5);

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Could not load price preview');
        expect($result['preview'])->toBeNull();
    });
});

describe('Subscription billingInterval', function () {
    test('returns monthly for monthly plan', function () {
        config()->set('subscription.stripe_price_id_dynamic_monthly', 'price_monthly_123');

        $this->subscription->update(['stripe_plan_id' => 'price_monthly_123']);
        $this->subscription->refresh();

        expect($this->subscription->billingInterval())->toBe('monthly');
    });

    test('returns yearly for yearly plan', function () {
        config()->set('subscription.stripe_price_id_dynamic_yearly', 'price_yearly_123');

        $this->subscription->update(['stripe_plan_id' => 'price_yearly_123']);
        $this->subscription->refresh();

        expect($this->subscription->billingInterval())->toBe('yearly');
    });

    test('defaults to monthly when plan id is null', function () {
        $this->subscription->update(['stripe_plan_id' => null]);
        $this->subscription->refresh();

        expect($this->subscription->billingInterval())->toBe('monthly');
    });
});
