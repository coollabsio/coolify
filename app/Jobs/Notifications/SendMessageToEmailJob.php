<?php

namespace App\Jobs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendMessageToEmailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    public int $maxExceptions = 5;

    public int $timeout = 30;

    public function __construct(
        private readonly MailMessage $message,
        private readonly array $recipients
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            Mail::html(
                (string) $this->message->render(),
                fn ($message) => $message
                    ->to($this->recipients)
                    ->subject($this->message->subject)
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to send email the following error occurred: {$e->getMessage()}"
            );
        }
    }
}
