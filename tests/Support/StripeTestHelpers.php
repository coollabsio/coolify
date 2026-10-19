<?php

use App\Actions\Stripe\FindOpenStripeSubscriptions;
use Mockery\MockInterface;
use Stripe\Subscription as StripeSubscription;

/**
 * Makes FindOpenStripeSubscriptions return the given Stripe subscriptions instead of calling Stripe.
 *
 * @param  list<array<string, mixed>>  $subscriptions
 */
function fakeOpenStripeSubscriptions(array $subscriptions = []): MockInterface
{
    $lookup = Mockery::mock(FindOpenStripeSubscriptions::class);
    $lookup->shouldReceive('handle')->andReturn(
        collect($subscriptions)->map(fn (array $subscription) => StripeSubscription::constructFrom($subscription))->keyBy('id')
    )->byDefault();
    app()->instance(FindOpenStripeSubscriptions::class, $lookup);

    return $lookup;
}
