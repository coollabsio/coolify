<?php

use App\Actions\Stripe\CreateCheckoutSession;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\CustomerService;
use Stripe\Service\SubscriptionService;
use Stripe\Stripe;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->stripe = Mockery::mock(StripeClient::class);
    $this->checkoutSessions = Mockery::mock(SessionService::class);
    $this->customers = Mockery::mock(CustomerService::class);
    $this->stripeSubscriptions = Mockery::mock(SubscriptionService::class);
    $this->stripe->checkout = (object) ['sessions' => $this->checkoutSessions];
    $this->stripe->customers = $this->customers;
    $this->stripe->subscriptions = $this->stripeSubscriptions;
});

function stripeCheckoutCollection(array $data): object
{
    return (object) ['data' => $data];
}

test('two near-simultaneous checkout requests reuse one open session', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_existing']);

    $openSession = (object) ['id' => 'cs_open', 'url' => 'https://checkout.stripe.test/cs_open', 'status' => 'open', 'mode' => 'subscription', 'subscription' => null];

    $this->stripeSubscriptions->shouldReceive('all')->twice()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('all')->twice()->andReturn(stripeCheckoutCollection([]), stripeCheckoutCollection([$openSession]));
    $this->checkoutSessions->shouldReceive('create')->once()->andReturn($openSession);
    $this->checkoutSessions->shouldReceive('allLineItems')->with('cs_open')->once()->andReturn(stripeCheckoutCollection([(object) ['price' => (object) ['id' => 'price_monthly']]]));

    $action = new CreateCheckoutSession($this->stripe);
    $first = $action->execute($this->team, $this->user, 'price_monthly');
    $second = $action->execute($this->team, $this->user, 'price_monthly');

    expect($first->id)->toBe('cs_open')->and($second->id)->toBe('cs_open');
});

test('separate checkout attempts let the SDK manage idempotency', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_existing']);
    $session = (object) ['id' => 'cs_retry', 'url' => 'https://checkout.stripe.test/cs_retry'];
    $payloads = [];
    $originalRetries = Stripe::getMaxNetworkRetries();

    $this->stripeSubscriptions->shouldReceive('all')->twice()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('all')->twice()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('create')->twice()
        ->withArgs(function (array $payload, array $options = []) use (&$payloads): bool {
            expect($options)->not->toHaveKey('idempotency_key');
            expect(Stripe::getMaxNetworkRetries())->toBe(2);
            $payloads[] = $payload;

            return $payload['customer'] === 'cus_existing';
        })->andReturn($session);

    $action = new CreateCheckoutSession($this->stripe);
    $action->execute($this->team, $this->user, 'price_monthly');
    $this->travel(5)->seconds();
    $action->execute($this->team, $this->user, 'price_monthly');

    expect($payloads)->toHaveCount(2)
        ->and($payloads[1]['expires_at'])->toBeGreaterThan($payloads[0]['expires_at'])
        ->and(Stripe::getMaxNetworkRetries())->toBe($originalRetries);
});

test('an existing active Stripe subscription blocks checkout', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_existing', 'stripe_subscription_id' => 'sub_active']);
    $this->stripeSubscriptions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([(object) ['id' => 'sub_active', 'status' => 'active']]));
    $this->checkoutSessions->shouldNotReceive('create');

    expect(fn () => (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_monthly'))
        ->toThrow(RuntimeException::class, 'active subscription');
});

test('an expired checkout session permits a new checkout', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_existing']);
    $expired = (object) ['id' => 'cs_expired', 'status' => 'expired', 'mode' => 'subscription', 'subscription' => null];

    $this->stripeSubscriptions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([$expired]));
    $this->checkoutSessions->shouldReceive('create')->once()
        ->withArgs(fn (array $payload, array $options = []): bool => $payload['customer'] === 'cus_existing' && ! isset($options['idempotency_key']))
        ->andReturn((object) ['id' => 'cs_new', 'url' => 'https://checkout.stripe.test/cs_new']);

    $session = (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_monthly');
    expect($session->id)->toBe('cs_new');
});

test('checkout creates and persists one Stripe customer for later attempts', function () {
    $this->customers->shouldReceive('create')->once()
        ->withArgs(fn (array $payload, array $options): bool => $payload['metadata']['team_id'] === $this->team->id && $options['idempotency_key'] === 'coolify-team-'.$this->team->id.'-customer')
        ->andReturn((object) ['id' => 'cus_new']);
    $this->stripeSubscriptions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('create')->once()
        ->withArgs(function (array $payload): bool {
            expect($payload['automatic_tax']['enabled'])->toBeTrue()
                ->and($payload['billing_address_collection'])->toBe('required')
                ->and($payload['customer_update'])->toMatchArray([
                    'name' => 'auto',
                    'address' => 'auto',
                ]);

            return $payload['customer'] === 'cus_new';
        })
        ->andReturn((object) ['id' => 'cs_new', 'url' => 'https://checkout.stripe.test/cs_new']);

    (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_monthly');
    expect($this->team->fresh()->subscription->stripe_customer_id)->toBe('cus_new');
});

test('a monthly subscription can move to yearly after Stripe confirms the old subscription ended', function () {
    config()->set('subscription.stripe_price_id_dynamic_monthly', 'price_monthly');
    config()->set('subscription.stripe_price_id_dynamic_yearly', 'price_yearly');

    Subscription::create([
        'team_id' => $this->team->id,
        'stripe_customer_id' => 'cus_existing',
        'stripe_subscription_id' => 'sub_monthly_ended',
        'stripe_plan_id' => 'price_monthly',
        'stripe_invoice_paid' => false,
    ]);

    $this->customers->shouldNotReceive('create');
    $this->stripeSubscriptions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([
        (object) ['id' => 'sub_monthly_ended', 'status' => 'canceled'],
    ]));
    $this->checkoutSessions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('create')->once()
        ->withArgs(fn (array $payload): bool => $payload['customer'] === 'cus_existing' && $payload['line_items'][0]['price'] === 'price_yearly')
        ->andReturn((object) ['id' => 'cs_yearly', 'url' => 'https://checkout.stripe.test/cs_yearly']);

    $session = (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_yearly');

    expect($session->id)->toBe('cs_yearly')
        ->and($this->team->fresh()->subscription->stripe_customer_id)->toBe('cus_existing');
});

test('the checkout lock blocks a concurrent request before it calls Stripe', function () {
    $lock = Cache::lock(CreateCheckoutSession::lockKey($this->team->id), 30);
    expect($lock->get())->toBeTrue();

    $this->customers->shouldNotReceive('create');
    $this->stripeSubscriptions->shouldNotReceive('all');
    $this->checkoutSessions->shouldNotReceive('create');

    try {
        expect(fn () => (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_monthly'))
            ->toThrow(RuntimeException::class, 'already being created');
    } finally {
        $lock->release();
    }
});

test('a Stripe connection failure releases the lock without creating another session', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_existing']);
    $originalRetries = Stripe::getMaxNetworkRetries();
    $this->stripeSubscriptions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('create')->once()->andThrow(new ApiConnectionException('Connection failed'));

    expect(fn () => (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_monthly'))
        ->toThrow(ApiConnectionException::class);
    expect(Stripe::getMaxNetworkRetries())->toBe($originalRetries);
    $lock = Cache::lock(CreateCheckoutSession::lockKey($this->team->id), 30);
    try {
        expect($lock->get())->toBeTrue();
    } finally {
        $lock->release();
    }
});

test('changing checkout price expires the old session before creating the selected plan', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_existing']);
    $this->stripeSubscriptions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([(object) ['id' => 'cs_monthly', 'mode' => 'subscription', 'status' => 'open']]));
    $this->checkoutSessions->shouldReceive('allLineItems')->with('cs_monthly')->once()->andReturn(stripeCheckoutCollection([(object) ['price' => (object) ['id' => 'price_monthly']]]));
    $this->checkoutSessions->shouldReceive('expire')->with('cs_monthly')->once()->globally()->ordered()->andReturn((object) ['status' => 'expired']);
    $this->checkoutSessions->shouldReceive('create')->once()->globally()->ordered()
        ->withArgs(fn (array $payload): bool => $payload['line_items'][0]['price'] === 'price_yearly')
        ->andReturn((object) ['id' => 'cs_yearly']);
    $session = (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_yearly');
    expect($session->id)->toBe('cs_yearly');
});

test('failed session expiration never creates a second checkout', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_existing']);
    $this->stripeSubscriptions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([]));
    $this->checkoutSessions->shouldReceive('all')->once()->andReturn(stripeCheckoutCollection([(object) ['id' => 'cs_monthly', 'mode' => 'subscription', 'status' => 'open']]));
    $this->checkoutSessions->shouldReceive('allLineItems')->once()->andReturn(stripeCheckoutCollection([(object) ['price' => (object) ['id' => 'price_monthly']]]));
    $this->checkoutSessions->shouldReceive('expire')->once()->andThrow(new ApiConnectionException('Cannot confirm expiration'));
    $this->checkoutSessions->shouldNotReceive('create');
    expect(fn () => (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_yearly'))
        ->toThrow(ApiConnectionException::class);
});
