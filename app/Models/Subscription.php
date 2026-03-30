<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'stripe_invoice_paid',
        'stripe_subscription_id',
        'stripe_customer_id',
        'stripe_cancel_at_period_end',
        'stripe_plan_id',
        'stripe_feedback',
        'stripe_comment',
        'stripe_trial_already_ended',
        'stripe_past_due',
        'stripe_refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'stripe_refunded_at' => 'datetime',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function billingInterval(): string
    {
        if ($this->stripe_plan_id) {
            $configKey = collect(config('subscription'))
                ->search($this->stripe_plan_id);

            if ($configKey && str($configKey)->contains('yearly')) {
                return 'yearly';
            }
        }

        return 'monthly';
    }

    public function type()
    {
        if (isStripe()) {
            if (! $this->stripe_plan_id) {
                return 'zero';
            }
            $subscription = Subscription::where('id', $this->id)->first();
            if (! $subscription) {
                return null;
            }
            $subscriptionPlanId = data_get($subscription, 'stripe_plan_id');
            if (! $subscriptionPlanId) {
                return null;
            }
            $subscriptionInvoicePaid = data_get($subscription, 'stripe_invoice_paid');
            if (! $subscriptionInvoicePaid) {
                return null;
            }
            $subscriptionConfigs = collect(config('subscription'));
            $stripePlanId = null;
            $subscriptionConfigs->map(function ($value, $key) use ($subscriptionPlanId, &$stripePlanId) {
                if ($value === $subscriptionPlanId) {
                    $stripePlanId = $key;
                }
            })->first();
            if ($stripePlanId) {
                return str($stripePlanId)->after('stripe_price_id_')->before('_')->lower();
            }
        }

        return 'zero';
    }
}
