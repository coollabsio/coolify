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
use Throwable;

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
            $session = getStripeCustomerPortalSession($this->team);
            $mailMessage = new MailMessage;
            $mailMessage->view('emails.subscription-invoice-failed', [
                'stripeCustomerPortal' => $session->url,
            ]);
            $mailMessage->subject('Your last payment was failed for Coolify Cloud.');
            $this->team->members()->each(function ($member) use ($mailMessage) {
                if ($member->isAdmin()) {
                    send_user_an_email($mailMessage, $member->email);
                }
            });
        } catch (Throwable $e) {
            send_internal_notification('SubscriptionInvoiceFailedJob failed with: '.$e->getMessage());
            throw $e;
        }
    }
}
