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

class SendMessageToSlackJob implements ShouldBeEncrypted, ShouldQueue
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
            Log::warning('SendMessageToSlackJob: blocked unsafe webhook URL', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->webhookUrl),
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        try {
            $httpOptions = SafeWebhookUrl::httpClientOptions($this->webhookUrl);
        } catch (\RuntimeException $e) {
            Log::warning('SendMessageToSlackJob: blocked unsafe webhook URL at send time', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->webhookUrl),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($this->isSlackWebhook()) {
            $this->sendToSlack($httpOptions);

            return;
        }

        /**
         * This works with Mattermost and as a fallback also with Slack, the notifications just look slightly different and advanced formatting for slack is not supported with Mattermost.
         *
         * @see https://github.com/coollabsio/coolify/pull/6139#issuecomment-3756777708
         */
        $this->sendToMattermost($httpOptions);
    }

    private function isSlackWebhook(): bool
    {
        $parsedUrl = parse_url($this->webhookUrl);

        if ($parsedUrl === false) {
            return false;
        }

        $scheme = $parsedUrl['scheme'] ?? '';
        $host = $parsedUrl['host'] ?? '';

        return $scheme === 'https' && $host === 'hooks.slack.com';
    }

    /**
     * @param  array<string, mixed>  $httpOptions
     */
    private function sendToSlack(array $httpOptions): void
    {
        Http::withOptions($httpOptions)->post($this->webhookUrl, [
            'text' => $this->message->title,
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

    /**
     * @todo v5 refactor: Extract this into a separate SendMessageToMattermostJob.php triggered via the "mattermost" notification channel type.
     */
    /**
     * @param  array<string, mixed>  $httpOptions
     */
    private function sendToMattermost(array $httpOptions): void
    {
        $username = config('app.name');

        Http::withOptions($httpOptions)->post($this->webhookUrl, [
            'username' => $username,
            'attachments' => [
                [
                    'title' => $this->message->title,
                    'color' => $this->message->color,
                    'text' => $this->message->description,
                    'footer' => $username,
                ],
            ],
        ]);
    }
}
