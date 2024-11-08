<?php

namespace App\Livewire\Subscription;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class PricingPlans extends Component
{
    public function subscribeStripe($type)
    {
        Stripe::setApiKey(config('subscription.stripe_api_key'));

        $priceId = match ($type) {
            'dynamic-monthly' => config('subscription.stripe_price_id_dynamic_monthly'),
            'dynamic-yearly' => config('subscription.stripe_price_id_dynamic_yearly'),
            default => config('subscription.stripe_price_id_dynamic_monthly'),
        };

        if (! $priceId) {
            $this->dispatch('error', 'Price ID not found! Please contact the administrator.');

            return;
        }
        $payload = [
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'required',
            'client_reference_id' => Auth::id().':'.currentTeam()->id,
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
                    'user_id' => Auth::id(),
                    'team_id' => currentTeam()->id,
                ],
            ],
            'payment_method_collection' => 'if_required',
            'mode' => 'subscription',
            'success_url' => route('dashboard', ['success' => true]),
            'cancel_url' => route('subscription.index', ['cancelled' => true]),
        ];

        $customer = currentTeam()->subscription?->stripe_customer_id ?? null;
        if ($customer) {
            $payload['customer'] = $customer;
            $payload['customer_update'] = [
                'name' => 'auto',
            ];
        } else {
            $payload['customer_email'] = Auth::user()->email;
        }
        $session = Session::create($payload);

        return redirect($session->url, 303);
    }
}
