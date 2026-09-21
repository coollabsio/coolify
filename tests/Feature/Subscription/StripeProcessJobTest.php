<?php

use App\Jobs\ServerLimitCheckJob;
use App\Jobs\StripeProcessJob;
use App\Jobs\SubscriptionInvoiceFailedJob;
use App\Jobs\VerifyStripeSubscriptionStatusJob;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    test('created event cannot overwrite a different recorded subscription', function () {
        Queue::fake();

        $rootTeam = Team::factory()->create(['id' => 0]);
        $rootTeam->discordNotificationSettings()->update(['discord_enabled' => true]);
        Notification::fake();

        Subscription::create([
            'team_id' => $this->team->id,
            'stripe_subscription_id' => 'sub_old',
            'stripe_customer_id' => 'cus_old',
            'stripe_invoice_paid' => true,
        ]);

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

        expect(Subscription::where('team_id', $this->team->id)->count())->toBe(1);
        $subscription = Subscription::where('team_id', $this->team->id)->first();
        expect($subscription->stripe_subscription_id)->toBe('sub_old');
        expect($subscription->stripe_customer_id)->toBe('cus_old');
        expect($subscription->stripe_invoice_paid)->toBeTruthy();

        Notification::assertSentTo($rootTeam, GeneralNotification::class, function (GeneralNotification $notification) {
            return str_contains($notification->message, 'StripeProcessJob error:')
                && str_contains($notification->message, 'cus_old')
                && str_contains($notification->message, 'cus_new_123');
        });
    });

    test('created event rejects a pending record with a different stripe customer id', function () {
        Queue::fake();

        $rootTeam = Team::factory()->create(['id' => 0]);
        $rootTeam->discordNotificationSettings()->update(['discord_enabled' => true]);
        Notification::fake();

        Subscription::create([
            'team_id' => $this->team->id,
            'stripe_customer_id' => 'cus_pending',
            'stripe_invoice_paid' => false,
        ]);

        (new StripeProcessJob(['type' => 'customer.subscription.created', 'data' => ['object' => [
            'id' => 'sub_other',
            'customer' => 'cus_other',
            'metadata' => ['team_id' => $this->team->id, 'user_id' => $this->user->id],
        ]]]))->handle();

        $subscription = $this->team->subscription()->first();
        expect($subscription->stripe_subscription_id)->toBeNull()
            ->and($subscription->stripe_customer_id)->toBe('cus_pending')
            ->and($subscription->stripe_invoice_paid)->toBeFalsy();

        Notification::assertSentTo($rootTeam, GeneralNotification::class, function (GeneralNotification $notification) {
            return str_contains($notification->message, 'StripeProcessJob error:')
                && str_contains($notification->message, 'cus_pending')
                && str_contains($notification->message, 'cus_other');
        });
    });
});

describe('checkout.session.completed', function () {
    test('creates subscription for new team', function () {
        Queue::fake();

        $event = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'client_reference_id' => $this->user->id.':'.$this->team->id,
                    'subscription' => 'sub_checkout_123',
                    'customer' => 'cus_checkout_123',
                ],
            ],
        ];

        $job = new StripeProcessJob($event);
        $job->handle();

        $subscription = Subscription::where('team_id', $this->team->id)->first();
        expect($subscription)->not->toBeNull();
        expect($subscription->stripe_invoice_paid)->toBeTruthy();
    });

    test('updates existing subscription instead of duplicating', function () {
        Queue::fake();

        Subscription::create([
            'team_id' => $this->team->id,
            'stripe_subscription_id' => 'sub_old',
            'stripe_customer_id' => 'cus_old',
            'stripe_invoice_paid' => false,
        ]);

        $event = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'client_reference_id' => $this->user->id.':'.$this->team->id,
                    'subscription' => 'sub_checkout_new',
                    'customer' => 'cus_checkout_new',
                ],
            ],
        ];

        $job = new StripeProcessJob($event);
        $job->handle();

        expect(Subscription::where('team_id', $this->team->id)->count())->toBe(1);
        $subscription = Subscription::where('team_id', $this->team->id)->first();
        expect($subscription->stripe_subscription_id)->toBe('sub_checkout_new');
        expect($subscription->stripe_invoice_paid)->toBeTruthy();
    });
});

describe('customer.subscription.updated clamps quantity to subscription bounds', function () {
    test('quantity below MIN is clamped to 2', function () {
        Queue::fake();

        Subscription::create([
            'team_id' => $this->team->id,
            'stripe_subscription_id' => 'sub_existing',
            'stripe_customer_id' => 'cus_min_clamp_test',
            'stripe_invoice_paid' => true,
        ]);

        $event = [
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'customer' => 'cus_min_clamp_test',
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
                            'quantity' => 1,
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
        expect($this->team->custom_server_limit)->toBe(2);

        Queue::assertPushed(ServerLimitCheckJob::class);
    });

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

describe('missing subscription Stripe webhooks are ignored', function () {
    test('does not send internal notifications or queue follow-up jobs', function (array $event) {
        Queue::fake();

        $rootTeam = Team::factory()->create(['id' => 0]);
        $rootTeam->discordNotificationSettings()->update(['discord_enabled' => true]);

        Notification::fake();

        $job = new StripeProcessJob($event);
        $job->handle();

        Notification::assertNothingSent();
        Notification::assertNotSentTo($rootTeam, GeneralNotification::class);
        Queue::assertNotPushed(SubscriptionInvoiceFailedJob::class);
        Queue::assertNotPushed(VerifyStripeSubscriptionStatusJob::class);
    })->with([
        'invoice paid' => [[
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'customer' => 'cus_missing_invoice_paid',
                    'amount_paid' => 1000,
                    'subscription' => 'sub_missing_invoice_paid',
                    'lines' => [
                        'data' => [[
                            'plan' => ['id' => 'price_dynamic_monthly'],
                        ]],
                    ],
                ],
            ],
        ]],
        'invoice payment failed' => [[
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_missing_invoice_payment_failed',
                    'id' => 'in_missing_invoice_payment_failed',
                    'payment_intent' => null,
                ],
            ],
        ]],
        'payment intent payment failed' => [[
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_missing_payment_intent_failed',
                ],
            ],
        ]],
        'customer subscription deleted' => [[
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'customer' => 'cus_missing_subscription_deleted',
                    'id' => 'sub_missing_subscription_deleted',
                ],
            ],
        ]],
    ]);
});

test('late repeated subscription created events preserve confirmed payment', function () {
    Queue::fake();
    $completed = ['type' => 'checkout.session.completed', 'data' => ['object' => [
        'client_reference_id' => $this->user->id.':'.$this->team->id,
        'subscription' => 'sub_paid', 'customer' => 'cus_paid',
    ]]];
    (new StripeProcessJob($completed))->handle();
    $created = ['type' => 'customer.subscription.created', 'data' => ['object' => [
        'id' => 'sub_paid', 'customer' => 'cus_paid',
        'metadata' => ['team_id' => $this->team->id, 'user_id' => $this->user->id],
    ]]];
    foreach (range(1, 2) as $attempt) {
        (new StripeProcessJob($created))->handle();
        expect($this->team->subscription()->first()->stripe_invoice_paid)->toBeTruthy();
    }
    expect($this->team->subscription()->count())->toBe(1);
});

test('created event fills a pending customer record without granting access', function () {
    Queue::fake();
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_pending', 'stripe_invoice_paid' => false]);
    (new StripeProcessJob(['type' => 'customer.subscription.created', 'data' => ['object' => [
        'id' => 'sub_pending', 'customer' => 'cus_pending',
        'metadata' => ['team_id' => $this->team->id, 'user_id' => $this->user->id],
    ]]]))->handle();
    expect($this->team->subscription()->first()->stripe_subscription_id)->toBe('sub_pending')
        ->and($this->team->subscription()->first()->stripe_invoice_paid)->toBeFalsy();
});
