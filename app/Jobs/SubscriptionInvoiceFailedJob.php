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
