<?php

namespace App\Actions\Stripe;

use App\Models\Subscription;
use Illuminate\Support\Collection;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

/**
 * Finds the Stripe subscriptions that did not end, of every Stripe customer with one of the given
 * emails and of the given customers. One email can have more than one Stripe customer, and a
 * subscription can exist at Stripe without a subscription row in Coolify.
 */
class FindOpenStripeSubscriptions
{
    public const PAYING_STATUSES = [
        StripeSubscription::STATUS_ACTIVE,
        StripeSubscription::STATUS_TRIALING,
        StripeSubscription::STATUS_PAST_DUE,
    ];

    /**
     * @param  iterable<?string>  $emails
     * @param  iterable<?string>  $customerIds
     * @return Collection<string, StripeSubscription> keyed by subscription id
     */
    public function handle(iterable $emails, iterable $customerIds = []): Collection
    {
        $stripe = app(StripeClient::class);
        $customerIds = collect($customerIds)->filter()->values();

        // The email filter of Stripe is case-sensitive.
        $emails = collect($emails)->filter()->flatMap(fn (string $email): array => [$email, strtolower($email)])->unique();
        foreach ($emails as $email) {
            foreach ($stripe->customers->all(['email' => $email, 'limit' => 100])->autoPagingIterator() as $customer) {
                $customerIds->push($customer->id);
            }
        }

        $subscriptions = collect();
        foreach ($customerIds->unique() as $customerId) {
            // Without a status filter, Stripe lists every subscription that is not canceled.
            foreach ($stripe->subscriptions->all(['customer' => $customerId, 'limit' => 100])->autoPagingIterator() as $subscription) {
                if ($subscription->status !== StripeSubscription::STATUS_INCOMPLETE_EXPIRED) {
                    $subscriptions->put($subscription->id, $subscription);
                }
            }
        }

        return $subscriptions;
    }

    /**
     * The team that records the subscription, else the team in its metadata.
     */
    public static function teamId(StripeSubscription $subscription): ?int
    {
        $teamId = Subscription::query()->where('stripe_subscription_id', $subscription->id)->value('team_id')
            ?? data_get($subscription, 'metadata.team_id');

        return is_numeric($teamId) ? (int) $teamId : null;
    }
}
