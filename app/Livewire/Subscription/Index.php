<?php

namespace App\Livewire\Subscription;

use App\Models\InstanceSettings;
use App\Providers\RouteServiceProvider;
use Livewire\Component;

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

    public function getStripeStatus()
    {
        try {
            $subscription = currentTeam()->subscription;
            $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));
            $customer = $stripe->customers->retrieve(currentTeam()->subscription->stripe_customer_id);
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
            logger()->error('Stripe API error: ' . $e->getMessage());
            // Set a flag to show an error message to the user
            $this->addError('stripe', 'Could not retrieve subscription information. Please try again later.');
        } finally {
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('livewire.subscription.index');
    }
}
