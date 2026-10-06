<?php

use App\Jobs\StripeProcessJob;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config()->set('subscription.stripe_api_key', 'sk_test_configured');
});

function stripeWebhookPayload(): string
{
    return json_encode([
        'id' => 'evt_security_test',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_test',
            'customer' => 'cus_test',
            'metadata' => ['team_id' => 999],
            'status' => 'unpaid',
        ]],
    ], JSON_THROW_ON_ERROR);
}

function stripeSignature(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, $secret));
}

function postStripeWebhook(string $payload, ?string $signature = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($signature !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/webhooks/payments/stripe/events', [], [], [], $server, $payload);
}

test('missing null and blank webhook secrets fail closed without dispatching work', function (?string $secret) {
    config()->set('subscription.stripe_webhook_secret', $secret);
    $payload = stripeWebhookPayload();

    postStripeWebhook($payload, stripeSignature($payload, trim((string) $secret)))
        ->assertBadRequest()
        ->assertContent('Invalid signature.');

    Queue::assertNotPushed(StripeProcessJob::class);
})->with([
    'null' => null,
    'empty' => '',
    'spaces' => '   ',
]);

test('missing null and blank Stripe API keys disable the webhook', function (?string $apiKey) {
    config()->set('subscription.stripe_api_key', $apiKey);
    config()->set('subscription.stripe_webhook_secret', 'whsec_correct');
    $payload = stripeWebhookPayload();

    postStripeWebhook($payload, stripeSignature($payload, 'whsec_correct'))
        ->assertBadRequest()
        ->assertContent('Invalid signature.');

    Queue::assertNotPushed(StripeProcessJob::class);
})->with([
    'null' => null,
    'empty' => '',
    'spaces' => '   ',
]);

test('missing malformed invalid expired and wrong signatures fail closed', function (?string $signature) {
    config()->set('subscription.stripe_webhook_secret', 'whsec_correct');
    $payload = stripeWebhookPayload();

    if ($signature === 'expired') {
        $signature = stripeSignature($payload, 'whsec_correct', time() - 301);
    } elseif ($signature === 'wrong') {
        $signature = stripeSignature($payload, 'whsec_wrong');
    }

    postStripeWebhook($payload, $signature)
        ->assertBadRequest()
        ->assertContent('Invalid signature.');

    Queue::assertNotPushed(StripeProcessJob::class);
})->with([
    'missing' => null,
    'malformed' => 'not-a-stripe-signature',
    'invalid' => 't=123,v1=invalid',
    'expired' => 'expired',
    'wrong secret' => 'wrong',
]);

test('an event cannot change state when Stripe is not configured', function () {
    config()->set('constants.coolify.self_hosted', true);
    config()->set('subscription.stripe_webhook_secret', null);
    $team = Team::factory()->create();
    $subscription = Subscription::create([
        'team_id' => $team->id,
        'stripe_subscription_id' => 'sub_existing',
        'stripe_customer_id' => 'cus_existing',
        'stripe_invoice_paid' => true,
    ]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update(['is_usable' => true, 'is_reachable' => true]);
    $payload = stripeWebhookPayload();

    postStripeWebhook($payload, stripeSignature($payload, ''))->assertBadRequest();

    expect($subscription->fresh()->stripe_subscription_id)->toBe('sub_existing')
        ->and($subscription->fresh()->stripe_invoice_paid)->toBeTruthy()
        ->and($server->fresh()->settings->is_usable)->toBeTruthy()
        ->and($server->fresh()->settings->is_reachable)->toBeTruthy();
    Queue::assertNotPushed(StripeProcessJob::class);
});

test('a valid signature with a malformed payload is rejected before dispatch', function () {
    config()->set('subscription.stripe_webhook_secret', 'whsec_correct');
    $payload = '{malformed';

    postStripeWebhook($payload, stripeSignature($payload, 'whsec_correct'))
        ->assertBadRequest()
        ->assertContent('Invalid webhook.');

    Queue::assertNotPushed(StripeProcessJob::class);
});

test('a valid signed event is accepted in cloud and self-hosted modes', function (bool $selfHosted) {
    config()->set('constants.coolify.self_hosted', $selfHosted);
    config()->set('subscription.stripe_webhook_secret', 'whsec_correct');
    $payload = stripeWebhookPayload();

    postStripeWebhook($payload, stripeSignature($payload, 'whsec_correct'))->assertSuccessful();

    Queue::assertPushed(StripeProcessJob::class, 1);
})->with([
    'cloud' => false,
    'self-hosted with Stripe configured' => true,
]);
