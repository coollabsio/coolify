<?php

namespace App\Jobs;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SubscriptionInvoiceFailedJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Team $team)
    {
        $this->onQueue('high');
    }

    public function handle()
    {
        try {
            // Double-check subscription status before sending failure notification
            $subscription = $this->team->subscription;
            if ($subscription && $subscription->stripe_customer_id) {
                try {
                    $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));

                    if ($subscription->stripe_subscription_id) {
                        $stripeSubscription = $stripe->subscriptions->retrieve($subscription->stripe_subscription_id);

                        if (in_array($stripeSubscription->status, ['active', 'trialing'])) {
                            if (! $subscription->stripe_invoice_paid) {
                                $subscription->update([
                                    'stripe_invoice_paid' => true,
                                    'stripe_past_due' => false,
                                ]);
                            }

                            return;
                        }
                    }

                    $invoices = $stripe->invoices->all([
                        'customer' => $subscription->stripe_customer_id,
                        'limit' => 3,
                    ]);

                    foreach ($invoices->data as $invoice) {
                        if ($invoice->paid && $invoice->created > (time() - 3600)) {
                            $subscription->update([
                                'stripe_invoice_paid' => true,
                                'stripe_past_due' => false,
                            ]);

                            return;
                        }
                    }
                } catch (\Exception $e) {
                }
            }

            // If we reach here, payment genuinely failed
            $session = getStripeCustomerPortalSession($this->team);
            $mail = new MailMessage;
            $mail->view('emails.subscription-invoice-failed', [
                'stripeCustomerPortal' => $session->url,
            ]);
            $mail->subject('Your last payment was failed for Coolify Cloud.');
            $this->team->members()->each(function ($member) use ($mail) {
                if ($member->isAdmin()) {
                    send_user_an_email($mail, $member->email);
                }
            });
        } catch (\Throwable $e) {
            send_internal_notification('SubscriptionInvoiceFailedJob failed with: '.$e->getMessage());
            throw $e;
        }
    }
}
