<?php

namespace App\Jobs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

class SendMessageToSlackJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 10;
    public int $maxExceptions = 5;

    public function __construct(
        private readonly SlackMessage $message,
        private readonly string $webhookUrl
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $response = Http::timeout(15)->post($this->webhookUrl, [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Coolify Notification',
                    ],
                ],
            ],
            'attachments' => [
                [
                    'color' => $this->message->color,
                    'blocks' => [
                        [
                            'type' => 'header',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => $this->message->title,
                            ],
                        ],
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => $this->message->description,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        
        if (! $response->successful()) {
            throw new \RuntimeException(
                "Slack webhook failed with status {$response->status()}: {$response->body()}"
            );
        }
    }
}
