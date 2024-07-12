<?php

namespace App\Livewire\Subscription;

use App\Models\InstanceSettings;
use App\Providers\RouteServiceProvider;
use Livewire\Component;

class Index extends Component
{
    public InstanceSettings $settings;

    public bool $alreadySubscribed = false;

    public function mount()
    {
        if (! isCloud()) {
            return redirect(RouteServiceProvider::HOME);
        }
        if (auth()->user()?->isMember()) {
            return redirect()->route('dashboard');
        }
        if (data_get(currentTeam(), 'subscription') && isSubscriptionActive()) {
            return redirect()->route('subscription.show');
        }
        $this->settings = \App\Models\InstanceSettings::get();
        $this->alreadySubscribed = currentTeam()->subscription()->exists();
    }

    public function stripeCustomerPortal()
    {
        $session = getStripeCustomerPortalSession(currentTeam());
        if (is_null($session)) {
            return;
        }

        return redirect($session->url);
    }

    public function render()
    {
        return view('livewire.subscription.index');
    }
}
