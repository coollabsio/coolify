<?php

namespace App\Jobs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

class SendMessageToDiscordJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 10;
    public int $maxExceptions = 5;

    public function __construct(
        private readonly DiscordMessage $message,
        private readonly string $webhookUrl
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $response = Http::timeout(15)->post($this->webhookUrl, $this->message->toPayload());
        
        if (! $response->successful()) {
            throw new \RuntimeException(
                "Discord webhook failed with status {$response->status()}: {$response->body()}"
            );
        }
    }
}
