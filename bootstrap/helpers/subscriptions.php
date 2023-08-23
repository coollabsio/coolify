<?php

use Illuminate\Support\Carbon;

function getSubscriptionLink($type)
{
    $checkout_id = config("subscription.lemon_squeezy_checkout_id_$type");
    if (!$checkout_id) {
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

function is_subscription_active()
{
    $team = currentTeam();

    if (!$team) {
        return false;
    }
    if (isInstanceAdmin()) {
        return true;
    }
    $subscription = $team?->subscription;

    if (!$subscription) {
        return false;
    }
    $is_active = $subscription->lemon_status === 'active';

    return $is_active;
}
function is_subscription_in_grace_period()
{
    $team = currentTeam();
    if (!$team) {
        return false;
    }
    if (isInstanceAdmin()) {
        return true;
    }
    $subscription = $team?->subscription;
    if (!$subscription) {
        return false;
    }
    $is_still_grace_period = $subscription->lemon_ends_at &&
        Carbon::parse($subscription->lemon_ends_at) > Carbon::now();

    return $is_still_grace_period;
}
