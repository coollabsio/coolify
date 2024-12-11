<?php

use App\Models\Team;
use Stripe\Stripe;

function isSubscriptionActive()
{
    if (! isCloud()) {
        return false;
    }
    $team = currentTeam();
    if (! $team) {
        return false;
    }
    $subscription = $team?->subscription;

    if (is_null($subscription)) {
        return false;
    }
    if (isStripe()) {
        return $subscription->stripe_invoice_paid === true;
    }

    return false;
}
function isSubscriptionOnGracePeriod()
{
    $team = currentTeam();
    if (! $team) {
        return false;
    }
    $subscription = $team?->subscription;
    if (! $subscription) {
        return false;
    }
    if (isStripe()) {
        return $subscription->stripe_cancel_at_period_end;
    }

    return false;
}
function subscriptionProvider()
{
    return config('subscription.provider');
}
function isStripe()
{
    return config('subscription.provider') === 'stripe';
}
function getStripeCustomerPortalSession(Team $team)
{
    Stripe::setApiKey(config('subscription.stripe_api_key'));
    $return_url = route('subscription.show');
    $stripe_customer_id = data_get($team, 'subscription.stripe_customer_id');
    if (! $stripe_customer_id) {
        return null;
    }

    return \Stripe\BillingPortal\Session::create([
        'customer' => $stripe_customer_id,
        'return_url' => $return_url,
    ]);
}
function allowedPathsForUnsubscribedAccounts()
{
    return [
        'subscription/new',
        'login',
        'logout',
        'force-password-reset',
        'livewire/update',
    ];
}
function allowedPathsForBoardingAccounts()
{
    return [
        ...allowedPathsForUnsubscribedAccounts(),
        'onboarding',
        'livewire/update',
    ];
}
function allowedPathsForInvalidAccounts()
{
    return [
        'logout',
        'verify',
        'force-password-reset',
        'livewire/update',
    ];
}
