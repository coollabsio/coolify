<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = [];

    public function team()
    {
        return $this->belongsTo(Team::class);
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
