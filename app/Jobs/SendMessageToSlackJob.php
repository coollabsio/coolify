<?php

namespace App\Jobs;

use App\Notifications\Dto\SlackMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendMessageToSlackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private SlackMessage $message,
        private string $webhookUrl
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        Http::post($this->webhookUrl, [
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
    }
}
