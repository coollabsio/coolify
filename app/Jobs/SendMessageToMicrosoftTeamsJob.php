<?php

namespace App\Jobs;

use App\Notifications\Dto\SlackMessage;
use App\Rules\SafeWebhookUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SendMessageToMicrosoftTeamsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = 10;

    public function __construct(
        private SlackMessage $message,
        private string $webhookUrl
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $validator = Validator::make(
            ['webhook_url' => $this->webhookUrl],
            ['webhook_url' => ['required', 'url', new SafeWebhookUrl]]
        );

        if ($validator->fails()) {
            Log::warning('SendMessageToMicrosoftTeamsJob: blocked unsafe webhook URL', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->webhookUrl),
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        try {
            $httpOptions = SafeWebhookUrl::httpClientOptions($this->webhookUrl);
        } catch (\RuntimeException $e) {
            Log::warning('SendMessageToMicrosoftTeamsJob: blocked unsafe webhook URL at send time', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->webhookUrl),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Http::withOptions($httpOptions)->post($this->webhookUrl, self::messageCard($this->message));
    }

    /**
     * Build the MessageCard payload Microsoft Teams incoming webhooks expect.
     *
     * @return array<string, mixed>
     */
    public static function messageCard(SlackMessage $message): array
    {
        return [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => ltrim($message->color, '#'),
            'summary' => $message->title,
            'sections' => [
                [
                    'activityTitle' => $message->title,
                    'text' => $message->description,
                    'markdown' => true,
                ],
            ],
        ];
    }
}
