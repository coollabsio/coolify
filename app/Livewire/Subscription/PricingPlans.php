<?php

namespace App\Livewire\Subscription;

use Livewire\Component;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class PricingPlans extends Component
{
    public bool $isTrial = false;

    public function mount()
    {
        $this->isTrial = ! data_get(currentTeam(), 'subscription.stripe_trial_already_ended');
        if (config('constants.limits.trial_period') == 0) {
            $this->isTrial = false;
        }
    }

    public function subscribeStripe($type)
    {
        $team = currentTeam();
        Stripe::setApiKey(config('subscription.stripe_api_key'));
        switch ($type) {
            case 'basic-monthly':
                $priceId = config('subscription.stripe_price_id_basic_monthly');
                break;
            case 'basic-yearly':
                $priceId = config('subscription.stripe_price_id_basic_yearly');
                break;
            case 'pro-monthly':
                $priceId = config('subscription.stripe_price_id_pro_monthly');
                break;
            case 'pro-yearly':
                $priceId = config('subscription.stripe_price_id_pro_yearly');
                break;
            case 'ultimate-monthly':
                $priceId = config('subscription.stripe_price_id_ultimate_monthly');
                break;
            case 'ultimate-yearly':
                $priceId = config('subscription.stripe_price_id_ultimate_yearly');
                break;
            case 'dynamic-monthly':
                $priceId = config('subscription.stripe_price_id_dynamic_monthly');
                break;
            case 'dynamic-yearly':
                $priceId = config('subscription.stripe_price_id_dynamic_yearly');
                break;
            default:
                $priceId = config('subscription.stripe_price_id_basic_monthly');
                break;
        }
        if (! $priceId) {
            $this->dispatch('error', 'Price ID not found! Please contact the administrator.');

            return;
        }
        $payload = [
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'required',
            'client_reference_id' => auth()->user()->id.':'.currentTeam()->id,
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'tax_id_collection' => [
                'enabled' => true,
            ],
            'automatic_tax' => [
                'enabled' => true,
            ],

            'mode' => 'subscription',
            'success_url' => route('dashboard', ['success' => true]),
            'cancel_url' => route('subscription.index', ['cancelled' => true]),
        ];
        if (str($type)->contains('ultimate')) {
            $payload['line_items'][0]['adjustable_quantity'] = [
                'enabled' => true,
                'minimum' => 10,
            ];
            $payload['line_items'][0]['quantity'] = 10;
        }
        if (str($type)->contains('dynamic')) {
            $payload['line_items'][0]['adjustable_quantity'] = [
                'enabled' => true,
                'minimum' => 2,
            ];
            $payload['line_items'][0]['quantity'] = 2;
        }

        if (! data_get($team, 'subscription.stripe_trial_already_ended')) {
            if (config('constants.limits.trial_period') > 0) {
                $payload['subscription_data'] = [
                    'trial_period_days' => config('constants.limits.trial_period'),
                    'trial_settings' => [
                        'end_behavior' => [
                            'missing_payment_method' => 'cancel',
                        ],
                    ],
                ];
            }
            $payload['payment_method_collection'] = 'if_required';
        }
        $customer = currentTeam()->subscription?->stripe_customer_id ?? null;
        if ($customer) {
            $payload['customer'] = $customer;
            $payload['customer_update'] = [
                'name' => 'auto',
            ];
        } else {
            $payload['customer_email'] = auth()->user()->email;
        }
        $session = Session::create($payload);

        return redirect($session->url, 303);
    }
}
