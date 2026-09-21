<?php

namespace App\Livewire\Subscription;

use App\Actions\Stripe\UpdateSubscriptionQuantity;
use App\Jobs\ServerLimitCheckJob;
use App\Models\InstanceSettings;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Stripe\StripeClient;

class Index extends Component
{
    public InstanceSettings $settings;

    public bool $alreadySubscribed = false;

    public bool $isUnpaid = false;

    public bool $isCancelled = false;

    public bool $isMember = false;

    public bool $loading = true;

    public function mount()
    {
        if (! isCloud()) {
            return redirect(RouteServiceProvider::HOME);
        }
        if (auth()->user()?->isMember()) {
            $this->isMember = true;
        }
        if (data_get(currentTeam(), 'subscription') && isSubscriptionActive()) {
            return redirect()->route('subscription.show');
        }
        $this->settings = instanceSettings();
        $this->alreadySubscribed = currentTeam()->subscription()->exists();
        if (! $this->alreadySubscribed) {
            $this->loading = false;
        }
    }

    public function stripeCustomerPortal()
    {
        $session = getStripeCustomerPortalSession(currentTeam());
        if (is_null($session)) {
            return;
        }

        return redirect($session->url);
    }

    public function getStripeStatus(): mixed
    {
        $team = currentTeam();
        $user = auth()->user();
        abort_unless($team && $user?->isAdminOfTeam($team->id), 403);

        try {
            $subscription = $team->subscription()->first();
            if (! $subscription?->stripe_customer_id) {
                return null;
            }
            $stripe = app(StripeClient::class);
            $customer = $stripe->customers->retrieve($subscription->stripe_customer_id);
            if ($customer) {
                $subscriptions = $stripe->subscriptions->all(['customer' => $customer->id]);
                $currentTeam = currentTeam()->id ?? null;
                if (count($subscriptions->data) > 0 && $currentTeam) {
                    $foundSubscription = collect($subscriptions->data)->firstWhere('metadata.team_id', $currentTeam);
                    if ($foundSubscription) {
                        $status = data_get($foundSubscription, 'status');
                        $subscription->update([
                            'stripe_subscription_id' => $foundSubscription->id,
                        ]);
                        if ($status === 'active') {
                            $subscription->update([
                                'stripe_invoice_paid' => true,
                                'stripe_past_due' => false,
                                'stripe_plan_id' => data_get($foundSubscription, 'items.data.0.price.id'),
                                'stripe_cancel_at_period_end' => data_get($foundSubscription, 'cancel_at_period_end', false),
                            ]);
                            if (str(data_get($foundSubscription, 'items.data.0.price.lookup_key'))->contains('dynamic')) {
                                $quantity = max(
                                    UpdateSubscriptionQuantity::MIN_SERVER_LIMIT,
                                    min((int) data_get($foundSubscription, 'items.data.0.quantity', 2), UpdateSubscriptionQuantity::MAX_SERVER_LIMIT)
                                );
                                $team->update(['custom_server_limit' => $quantity]);
                                ServerLimitCheckJob::dispatch($team);
                            }
                            $team->unsetRelation('subscription');
                            Cache::forget('user:'.$user->id.':team:'.$team->id);

                            return redirect()->route('subscription.show');
                        }
                        if ($status === 'unpaid') {
                            $this->isUnpaid = true;
                        }
                    }
                }
                if (count($subscriptions->data) === 0) {
                    $this->isCancelled = true;
                }
            }
        } catch (\Exception $e) {
            // Log the error
            logger()->error('Stripe API error: '.$e->getMessage());
            // Set a flag to show an error message to the user
            $this->addError('stripe', 'Could not retrieve subscription information. Please try again later.');
        } finally {
            $this->loading = false;
        }

        return null;
    }

    public function render()
    {
        return view('livewire.subscription.index');
    }
}
