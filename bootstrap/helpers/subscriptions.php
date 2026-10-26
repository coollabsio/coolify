<?php

use App\Models\Team;
use Stripe\BillingPortal\Session;
use Stripe\Customer;
use Stripe\Stripe;

function isSubscriptionActive()
{
    return once(function () {
        if (! isCloud()) {
            return false;
        }
        $team = currentTeam();
        if (! $team) {
            return false;
        }
        // Root team (id=0) doesn't require subscription
        if ($team->id === 0) {
            return true;
        }
        $subscription = $team?->subscription;

        if (is_null($subscription)) {
            return false;
        }
        if (isStripe()) {
            return $subscription->stripe_invoice_paid === true;
        }

        return false;
    });
}

function isSubscriptionOnGracePeriod()
{
    return once(function () {
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
    });
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

    return Session::create([
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
        'two-factor-challenge',
        'livewire/update',
        'admin',
        // Account basics stay available without a paid plan.
        'profile',
        'profile/appearance',
        'profile/passkeys/confirm',
        'user/confirm-password',
        'user/passkeys',
        'user/passkeys/options',
        'passkeys/confirm',
        'passkeys/confirm/options',
        ...allowedPathsForTwoFactorSetup(),
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
        'two-factor-challenge',
        'livewire/update',
        ...allowedPathsForTwoFactorSetup(),
    ];
}
/**
 * Paths a user needs to configure 2FA when their team requires it.
 *
 * @return array<int, string>
 */
function allowedPathsForTwoFactorSetup(): array
{
    return [
        'two-factor-required',
        'two-factor-challenge',
        'logout',
        'user/confirm-password',
        'user/confirmed-password-status',
        'user/two-factor-authentication',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-qr-code',
        'user/two-factor-secret-key',
        'user/two-factor-recovery-codes',
    ];
}

function updateStripeCustomerEmail(Team $team, string $newEmail): void
{
    if (! isStripe()) {
        return;
    }

    $stripe_customer_id = data_get($team, 'subscription.stripe_customer_id');
    if (! $stripe_customer_id) {
        return;
    }

    Stripe::setApiKey(config('subscription.stripe_api_key'));

    Customer::update(
        $stripe_customer_id,
        ['email' => $newEmail]
    );
}
