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
            "username" => "Coolify",
            "icon_url" => "https://coolify.io/docs/coolify-logo-transparent.png",
            "attachments" => [
                [
                    "title" => $this->message->title,
                    "color" => $this->message->color,
                    "text" => $this->message->description,
                    "footer" => "Coolify"
                ]
            ]
        ]);
    }
}
