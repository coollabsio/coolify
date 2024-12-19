<?php

namespace App\Jobs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SendMessageToTelegramJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    public int $maxExceptions = 5;

    public function __construct(
        private readonly string $text,
        private readonly array $buttons,
        private readonly string $token,
        private readonly string $chatId,
        private readonly ?string $threadId = null,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $inlineButtons = [];
        foreach ($this->buttons as $button) {
            $buttonUrl = data_get($button, 'url');
            $text = data_get($button, 'text', 'Click here');

            if ($buttonUrl && Str::contains($buttonUrl, 'http://localhost')) {
                $buttonUrl = str_replace('http://localhost', config('app.url'), $buttonUrl);
            }

            $inlineButtons[] = [
                'text' => $text,
                'url' => $buttonUrl,
            ];
        }

        $payload = [
            'chat_id' => $this->chatId,
            'text' => $this->text,
        ];

        if (! empty($this->buttons)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => [
                    $inlineButtons,
                ],
            ]);
        }

        if ($this->threadId) {
            $payload['message_thread_id'] = $this->threadId;
        }

        $response = Http::timeout(15)
            ->post('https://api.telegram.org/bot'.$this->token.'/sendMessage', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Telegram webhook failed with status {$response->status()}: {$response->body()}"
            );
        }
    }
}
