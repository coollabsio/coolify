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
        $username = config('app.name');
        $appUrl = config('app.url');
        $iconUrl = ($appUrl && !str_contains($appUrl, 'localhost') && !str_contains($appUrl, '127.0.0.1'))
            ? $appUrl . '/coolify-transparent.png'
            : 'https://coolify.io/docs/coolify-logo-transparent.png';

        Http::post($this->webhookUrl, [
            "username" => $username,
            "icon_url" => $iconUrl,
            "attachments" => [
                [
                    "title" => $this->message->title,
                    "color" => $this->message->color,
                    "text" => $this->message->description,
                    "footer" => $username
                ]
            ]
        ]);
    }
}
