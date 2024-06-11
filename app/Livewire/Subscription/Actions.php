<?php

namespace App\Livewire\Subscription;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class Actions extends Component
{
    public $server_limits = 0;

    public function mount()
    {
        $this->server_limits = Team::serverLimit();
    }

    public function cancel()
    {
        try {
            $subscription_id = currentTeam()->subscription->lemon_subscription_id;
            if (! $subscription_id) {
                throw new \Exception('No subscription found');
            }
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
                'Authorization' => 'Bearer '.config('subscription.lemon_squeezy_api_key'),
            ])->delete('https://api.lemonsqueezy.com/v1/subscriptions/'.$subscription_id);
            $json = $response->json();
            if ($response->failed()) {
                $error = data_get($json, 'errors.0.status');
                if ($error === '404') {
                    throw new \Exception('Subscription not found.');
                }
                throw new \Exception(data_get($json, 'errors.0.title', 'Something went wrong. Please try again later.'));
            } else {
                $this->dispatch('success', 'Subscription cancelled successfully. Reloading in 5s.');
                $this->dispatch('reloadWindow', 5000);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resume()
    {
        try {
            $subscription_id = currentTeam()->subscription->lemon_subscription_id;
            if (! $subscription_id) {
                throw new \Exception('No subscription found');
            }
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
                'Authorization' => 'Bearer '.config('subscription.lemon_squeezy_api_key'),
            ])->patch('https://api.lemonsqueezy.com/v1/subscriptions/'.$subscription_id, [
                'data' => [
                    'type' => 'subscriptions',
                    'id' => $subscription_id,
                    'attributes' => [
                        'cancelled' => false,
                    ],
                ],
            ]);
            $json = $response->json();
            if ($response->failed()) {
                $error = data_get($json, 'errors.0.status');
                if ($error === '404') {
                    throw new \Exception('Subscription not found.');
                }
                throw new \Exception(data_get($json, 'errors.0.title', 'Something went wrong. Please try again later.'));
            } else {
                $this->dispatch('success', 'Subscription resumed successfully. Reloading in 5s.');
                $this->dispatch('reloadWindow', 5000);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stripeCustomerPortal()
    {
        $session = getStripeCustomerPortalSession(currentTeam());
        redirect($session->url);
    }
}
