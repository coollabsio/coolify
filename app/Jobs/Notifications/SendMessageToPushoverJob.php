<?php

namespace App\Jobs\Notifications;

use App\Notifications\Dto\PushoverMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendMessageToPushoverJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    public int $maxExceptions = 5;

    public function __construct(
        private readonly PushoverMessage $message,
        private readonly string $token,
        private readonly string $user,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $response = Http::timeout(15)->post('https://api.pushover.net/1/messages.json', $this->message->toPayload($this->token, $this->user));

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Pushover notification failed with status {$response->status()}: {$response->body()}"
            );
        }
    }
}
