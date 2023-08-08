<?php

use Illuminate\Support\Carbon;

function getSubscriptionLink($id)
{
    $checkout_id = config("coolify.lemon_squeezy_checkout_id_$id");
    if (!$checkout_id) {
        return null;
    }
    $user_id = auth()->user()->id;
    $team_id = auth()->user()->currentTeam()->id ?? null;
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
    return auth()->user()->currentTeam()->subscription->lemon_update_payment_menthod_url;
}

function getRenewDate()
{
    return Carbon::parse(auth()->user()->currentTeam()->subscription->lemon_renews_at)->format('Y-M-d H:i:s');
}

function getEndDate()
{
    return Carbon::parse(auth()->user()->currentTeam()->subscription->lemon_renews_at)->format('Y-M-d H:i:s');
}

function isSubscribed()
{
    return
        auth()->user()?->currentTeam()?->subscription?->lemon_status === 'active' ||
        (auth()->user()?->currentTeam()?->subscription?->lemon_ends_at &&
            Carbon::parse(auth()->user()->currentTeam()->subscription->lemon_ends_at) > Carbon::now()
        ) || auth()->user()->isInstanceAdmin();
}
