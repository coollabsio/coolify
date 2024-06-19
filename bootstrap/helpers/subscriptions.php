<?php

use App\Models\Team;
use Illuminate\Support\Carbon;
use Stripe\Stripe;

function getSubscriptionLink($type)
{
    $checkout_id = config("subscription.lemon_squeezy_checkout_id_$type");
    if (! $checkout_id) {
        return null;
    }
    $user_id = auth()->user()->id;
    $team_id = currentTeam()->id ?? null;
    $email = auth()->user()->email ?? null;
    $name = auth()->user()->name ?? null;
    $url = "https://store.coollabs.io/checkout/buy/$checkout_id?";
    if ($user_id) {
        $url .= "&checkout[custom][user_id]={$user_id}";
    }
    if (isset($team_id)) {
        $url .= "&checkout[custom][team_id]={$team_id}";
    }
    if ($email) {
        $url .= "&checkout[email]={$email}";
    }
    if ($name) {
        $url .= "&checkout[name]={$name}";
    }

    return $url;
}

function getPaymentLink()
{
    return currentTeam()->subscription->lemon_update_payment_menthod_url;
}

function getRenewDate()
{
    return Carbon::parse(currentTeam()->subscription->lemon_renews_at)->format('Y-M-d H:i:s');
}

function getEndDate()
{
    return Carbon::parse(currentTeam()->subscription->lemon_renews_at)->format('Y-M-d H:i:s');
}

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
    if (isLemon()) {
        return $subscription->lemon_status === 'active';
    }
    // if (isPaddle()) {
    //     return $subscription->paddle_status === 'active';
    // }
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
    if (isLemon()) {
        $is_still_grace_period = $subscription->lemon_ends_at &&
            Carbon::parse($subscription->lemon_ends_at) > Carbon::now();

        return $is_still_grace_period;
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
function isLemon()
{
    return config('subscription.provider') === 'lemon';
}
function isStripe()
{
    return config('subscription.provider') === 'stripe';
}
function isPaddle()
{
    return config('subscription.provider') === 'paddle';
}
function getStripeCustomerPortalSession(Team $team)
{
    Stripe::setApiKey(config('subscription.stripe_api_key'));
    $return_url = route('subscription.show');
    $stripe_customer_id = data_get($team, 'subscription.stripe_customer_id');
    if (! $stripe_customer_id) {
        return null;
    }
    $session = \Stripe\BillingPortal\Session::create([
        'customer' => $stripe_customer_id,
        'return_url' => $return_url,
    ]);

    return $session;
}
function allowedPathsForUnsubscribedAccounts()
{
    return [
        'subscription/new',
        'login',
        'logout',
        'waitlist',
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
