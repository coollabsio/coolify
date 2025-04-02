<?php

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\EmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class Test extends CustomEmailNotification
{
    public function __construct(public string $emails, public string $isTransactionalEmail)
    {
        $this->onQueue('high');
        $this->isTransactionalEmail = true;
    }

    public function via(): array
    {
        return [EmailChannel::class];
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: Test Email');
        $mail->view('emails.test');

        return $mail;
    }
}
