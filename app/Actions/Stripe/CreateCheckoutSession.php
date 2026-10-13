<?php

namespace App\Actions\Stripe;

use App\Exceptions\CheckoutUnavailableException;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\StripeClient;
use Throwable;

class CreateCheckoutSession
{
    private const BLOCKING_SUBSCRIPTION_STATUSES = [
        'active',
        'incomplete',
        'past_due',
        'paused',
        'trialing',
        'unpaid',
    ];

    private const RECOVERABLE_SUBSCRIPTION_STATUSES = [
        'incomplete',
        'past_due',
        'paused',
        'unpaid',
    ];

    public function __construct(private ?StripeClient $stripe = null)
    {
        $this->stripe ??= app(StripeClient::class);
    }

    public static function lockKey(int $teamId): string
    {
        return "stripe-checkout:team:{$teamId}";
    }

    public function execute(Team $team, User $user, string $priceId): object
    {
        $lock = Cache::lock(self::lockKey($team->id), 30);

        if (! $lock->get()) {
            throw new CheckoutUnavailableException('A subscription checkout is already being created for this team.');
        }

        $previousMaxNetworkRetries = Stripe::getMaxNetworkRetries();
        Stripe::setMaxNetworkRetries(2);

        try {
            return $this->createOrReuseSession($team, $user, $priceId);
        } finally {
            Stripe::setMaxNetworkRetries($previousMaxNetworkRetries);
            $lock->release();
        }
    }

    private function createOrReuseSession(Team $team, User $user, string $priceId): object
    {
        $subscription = Subscription::query()->firstOrNew(['team_id' => $team->id]);
        $customerId = $subscription->stripe_customer_id;

        if (! $customerId) {
            $customer = $this->stripe->customers->create([
                'email' => $user->email,
                'metadata' => [
                    'team_id' => $team->id,
                ],
            ], [
                'idempotency_key' => "coolify-team-{$team->id}-customer",
            ]);
            $customerId = $customer->id;
            $subscription->stripe_customer_id = $customerId;
            $subscription->save();

            Log::info('Stripe customer assigned for subscription checkout.', [
                'team_id' => $team->id,
                'stripe_customer_id' => $customerId,
            ]);
        }

        $blockingSubscription = null;
        foreach ($this->stripe->subscriptions->all([
            'customer' => $customerId,
            'limit' => 10,
            'status' => 'all',
        ])->autoPagingIterator() as $stripeSubscription) {
            if (in_array($stripeSubscription->status, self::BLOCKING_SUBSCRIPTION_STATUSES, true)) {
                $blockingSubscription = $stripeSubscription;
                break;
            }
        }

        $this->throwIfBlockingSubscription($team, $customerId, $blockingSubscription);

        $sessions = $this->stripe->checkout->sessions->all([
            'customer' => $customerId,
            'limit' => 10,
            'status' => 'open',
        ]);
        $subscriptionSessions = collect($sessions->data)->filter(
            fn (object $session): bool => ($session->mode ?? null) === 'subscription'
        );
        $openSession = $subscriptionSessions->first(
            fn (object $session): bool => ($session->status ?? null) === 'open'
        );

        if ($openSession) {
            $lineItems = $this->stripe->checkout->sessions->allLineItems($openSession->id);
            if (count($lineItems->data) === 1 && data_get($lineItems, 'data.0.price.id') === $priceId) {
                Log::info('Reusing pending Stripe subscription checkout.', [
                    'team_id' => $team->id,
                    'stripe_customer_id' => $customerId,
                    'stripe_checkout_session_id' => $openSession->id,
                    'stripe_subscription_id' => $openSession->subscription ?? null,
                ]);

                return $openSession;
            }

            $this->stripe->checkout->sessions->expire($openSession->id);
        }

        $session = $this->stripe->checkout->sessions->create([
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'required',
            'client_reference_id' => $user->id.':'.$team->id,
            'customer' => $customerId,
            'customer_update' => [
                'name' => 'auto',
                'address' => 'auto',
            ],
            'line_items' => [[
                'price' => $priceId,
                'adjustable_quantity' => [
                    'enabled' => true,
                    'minimum' => 2,
                ],
                'quantity' => 2,
            ]],
            'tax_id_collection' => [
                'enabled' => true,
            ],
            'automatic_tax' => [
                'enabled' => true,
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => $user->id,
                    'team_id' => $team->id,
                ],
            ],
            'payment_method_collection' => 'if_required',
            'mode' => 'subscription',
            'expires_at' => now()->addMinutes(35)->timestamp,
            'success_url' => route('dashboard', ['success' => true]),
            'cancel_url' => route('subscription.index', ['cancelled' => true]),
        ]);

        Log::info('Stripe subscription checkout created.', [
            'team_id' => $team->id,
            'stripe_customer_id' => $customerId,
            'stripe_checkout_session_id' => $session->id,
            'stripe_subscription_id' => $session->subscription ?? null,
        ]);

        return $session;
    }

    private function throwIfBlockingSubscription(Team $team, string $customerId, ?object $blockingSubscription): void
    {
        if (! $blockingSubscription) {
            return;
        }

        Log::warning('Stripe subscription checkout blocked by existing subscription.', [
            'team_id' => $team->id,
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $blockingSubscription->id,
            'stripe_subscription_status' => $blockingSubscription->status,
        ]);

        $portalUrl = in_array($blockingSubscription->status, self::RECOVERABLE_SUBSCRIPTION_STATUSES, true)
            ? $this->billingPortalUrl($customerId)
            : null;

        throw new CheckoutUnavailableException(
            $this->blockingSubscriptionMessage($blockingSubscription->status),
            $portalUrl,
        );
    }

    private function blockingSubscriptionMessage(string $status): string
    {
        return match ($status) {
            'incomplete' => "This team's subscription payment is incomplete. Complete the payment in the billing portal.",
            'past_due' => "This team's subscription payment is past due. Update the payment method or settle the outstanding invoice in the billing portal.",
            'unpaid' => "This team's subscription is unpaid. Settle the outstanding invoice in the billing portal.",
            'paused' => "This team's subscription is paused. Resume it in the billing portal.",
            default => 'Team already has an active subscription.',
        };
    }

    private function billingPortalUrl(string $customerId): ?string
    {
        try {
            $session = $this->stripe->billingPortal->sessions->create([
                'customer' => $customerId,
                'return_url' => route('subscription.show'),
            ]);
        } catch (Throwable) {
            return null;
        }

        return is_string($session->url ?? null) ? $session->url : null;
    }
}
