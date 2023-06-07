<?php

namespace App\Notifications;

use App\Notifications\Channels\EmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestTransactionEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];
        $notifiable->extra_attributes?->get('smtp_host') && $channels[] = EmailChannel::class;
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject('Coolify Test Notification');
        $mail->view('emails.test-email');
        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
