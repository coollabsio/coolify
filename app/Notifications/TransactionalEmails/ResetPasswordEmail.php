<?php

namespace App\Notifications\TransactionalEmails;

use App\Models\User;
use App\Notifications\Channels\TransactionalEmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordEmail extends Notification implements ShouldQueue
{
    use Queueable;
    public string $token;
    public function __construct(string $token)
    {
        $this->token = $token;
    }
    public function via()
    {
        return [TransactionalEmailChannel::class];
    }

    public function toMail(User $user): MailMessage
    {
        $url = url('/') . '/reset-password/' . $this->token . '?email=' . $user->email;
        $mail = new MailMessage();
        $mail->subject('Reset Password');
        $mail->view('emails.reset-password', [
            'user' => $user,
            'url' => $url,
        ]);
        return $mail;
    }
}
