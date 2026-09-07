<?php

namespace App\Actions\Stripe;

use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Stripe;
use Stripe\StripeClient;

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
            throw new RuntimeException('A subscription checkout is already being created for this team.');
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

        $stripeSubscriptions = $this->stripe->subscriptions->all([
            'customer' => $customerId,
            'limit' => 10,
            'status' => 'all',
        ]);
        $blockingSubscription = collect($stripeSubscriptions->data)->first(
            fn (object $stripeSubscription): bool => in_array($stripeSubscription->status, self::BLOCKING_SUBSCRIPTION_STATUSES, true)
        );

        if ($blockingSubscription) {
            Log::warning('Stripe subscription checkout blocked by existing subscription.', [
                'team_id' => $team->id,
                'stripe_customer_id' => $customerId,
                'stripe_subscription_id' => $blockingSubscription->id,
                'stripe_subscription_status' => $blockingSubscription->status,
            ]);

            throw new RuntimeException('Team already has an active subscription.');
        }

        $sessions = $this->stripe->checkout->sessions->all([
            'customer' => $customerId,
            'limit' => 10,
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
            'expires_at' => now()->addMinutes(30)->timestamp,
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
}
