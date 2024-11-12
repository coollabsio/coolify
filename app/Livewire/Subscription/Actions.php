<?php

namespace App\Livewire\Subscription;

use App\Models\Team;
use Livewire\Component;

class Actions extends Component
{
    public $server_limits = 0;

    public function mount()
    {
        $this->server_limits = Team::serverLimit();
    }

    public function stripeCustomerPortal()
    {
        $session = getStripeCustomerPortalSession(currentTeam());
        redirect($session->url);
    }
}
