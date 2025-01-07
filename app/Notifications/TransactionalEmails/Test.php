<?php

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\EmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class Test extends CustomEmailNotification
{
    public function __construct(public string $emails)
    {
        $this->onQueue('high');
    }

    public function via(): array
    {
        return [EmailChannel::class];
    }

    public function toMail(): MailMessage
    {
        $mailMessage = new MailMessage;
        $mailMessage->subject('Coolify: Test Email');
        $mailMessage->view('emails.test');

        return $mailMessage;
    }
}
