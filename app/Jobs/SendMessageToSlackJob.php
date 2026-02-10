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
        if ($this->isSlackWebhook()) {
            $this->sendToSlack();

            return;
        }

        /**
         * This works with Mattermost and as a fallback also with Slack, the notifications just look slightly different and advanced formatting for slack is not supported with Mattermost.
         *
         * @see https://github.com/coollabsio/coolify/pull/6139#issuecomment-3756777708
         */
        $this->sendToMattermost();
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

    private function sendToSlack(): void
    {
        Http::post($this->webhookUrl, [
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
    private function sendToMattermost(): void
    {
        $username = config('app.name');

        Http::post($this->webhookUrl, [
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
