<?php

use App\Jobs\ServerLimitCheckJob;
use App\Jobs\StripeProcessJob;
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

describe('customer.subscription.created does not fall through to updated', function () {
    test('created event creates subscription without setting stripe_invoice_paid to true', function () {
        Queue::fake();

        $event = [
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'customer' => 'cus_new_123',
                    'id' => 'sub_new_123',
                    'metadata' => [
                        'team_id' => $this->team->id,
                        'user_id' => $this->user->id,
                    ],
                ],
            ],
        ];

        $job = new StripeProcessJob($event);
        $job->handle();

        $subscription = Subscription::where('team_id', $this->team->id)->first();

        expect($subscription)->not->toBeNull();
        expect($subscription->stripe_subscription_id)->toBe('sub_new_123');
        expect($subscription->stripe_customer_id)->toBe('cus_new_123');
        // Critical: stripe_invoice_paid must remain false — payment not yet confirmed
        expect($subscription->stripe_invoice_paid)->toBeFalsy();
    });
});

describe('customer.subscription.updated clamps quantity to MAX_SERVER_LIMIT', function () {
    test('quantity exceeding MAX is clamped to 100', function () {
        Queue::fake();

        Subscription::create([
            'team_id' => $this->team->id,
            'stripe_subscription_id' => 'sub_existing',
            'stripe_customer_id' => 'cus_clamp_test',
            'stripe_invoice_paid' => true,
        ]);

        $event = [
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'customer' => 'cus_clamp_test',
                    'id' => 'sub_existing',
                    'status' => 'active',
                    'metadata' => [
                        'team_id' => $this->team->id,
                        'user_id' => $this->user->id,
                    ],
                    'items' => [
                        'data' => [[
                            'subscription' => 'sub_existing',
                            'plan' => ['id' => 'price_dynamic_monthly'],
                            'price' => ['lookup_key' => 'dynamic_monthly'],
                            'quantity' => 999,
                        ]],
                    ],
                    'cancel_at_period_end' => false,
                    'cancellation_details' => ['feedback' => null, 'comment' => null],
                ],
            ],
        ];

        $job = new StripeProcessJob($event);
        $job->handle();

        $this->team->refresh();
        expect($this->team->custom_server_limit)->toBe(100);

        Queue::assertPushed(ServerLimitCheckJob::class);
    });
});

describe('ServerLimitCheckJob dispatch is guarded by team check', function () {
    test('does not dispatch ServerLimitCheckJob when team is null', function () {
        Queue::fake();

        // Create subscription without a valid team relationship
        $subscription = Subscription::create([
            'team_id' => 99999,
            'stripe_subscription_id' => 'sub_orphan',
            'stripe_customer_id' => 'cus_orphan_test',
            'stripe_invoice_paid' => true,
        ]);

        $event = [
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'customer' => 'cus_orphan_test',
                    'id' => 'sub_orphan',
                    'status' => 'active',
                    'metadata' => [
                        'team_id' => null,
                        'user_id' => null,
                    ],
                    'items' => [
                        'data' => [[
                            'subscription' => 'sub_orphan',
                            'plan' => ['id' => 'price_dynamic_monthly'],
                            'price' => ['lookup_key' => 'dynamic_monthly'],
                            'quantity' => 5,
                        ]],
                    ],
                    'cancel_at_period_end' => false,
                    'cancellation_details' => ['feedback' => null, 'comment' => null],
                ],
            ],
        ];

        $job = new StripeProcessJob($event);
        $job->handle();

        Queue::assertNotPushed(ServerLimitCheckJob::class);
    });
});
