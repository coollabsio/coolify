<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $lemon_subscription_id
 * @property string|null $lemon_order_id
 * @property string|null $lemon_product_id
 * @property string|null $lemon_variant_id
 * @property string|null $lemon_variant_name
 * @property string|null $lemon_customer_id
 * @property string|null $lemon_status
 * @property string|null $lemon_trial_ends_at
 * @property string|null $lemon_renews_at
 * @property string|null $lemon_ends_at
 * @property string|null $lemon_update_payment_menthod_url
 * @property int $team_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $stripe_invoice_paid
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_customer_id
 * @property bool $stripe_cancel_at_period_end
 * @property string|null $stripe_plan_id
 * @property string|null $stripe_feedback
 * @property string|null $stripe_comment
 * @property bool $stripe_trial_already_ended
 * @property-read \App\Models\Team|null $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription query()
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonRenewsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonSubscriptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonTrialEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonUpdatePaymentMenthodUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereLemonVariantName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereStripeCancelAtPeriodEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereStripeComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereStripeCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereStripeFeedback($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereStripeInvoicePaid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereStripePlanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereStripeSubscriptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereStripeTrialAlreadyEnded($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Subscription extends Model
{
    protected $guarded = [];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function type()
    {
        if (isLemon()) {
            $basic = explode(',', config('subscription.lemon_squeezy_basic_plan_ids'));
            $pro = explode(',', config('subscription.lemon_squeezy_pro_plan_ids'));
            $ultimate = explode(',', config('subscription.lemon_squeezy_ultimate_plan_ids'));

            $subscription = $this->lemon_variant_id;
            if (in_array($subscription, $basic)) {
                return 'basic';
            }
            if (in_array($subscription, $pro)) {
                return 'pro';
            }
            if (in_array($subscription, $ultimate)) {
                return 'ultimate';
            }
        } elseif (isStripe()) {
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
