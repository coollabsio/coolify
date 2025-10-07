<?php

namespace App\Notifications\Dto;

use Illuminate\Support\Facades\Log;

class GotifyMessage
{
    public function __construct(
        public string $title,
        public string $message,
        public array $buttons = [],
        public string $level = 'info',
    ) {}

    public function getPriority(): int
    {
        return match ($this->level) {
            'info' => 5,
            'error' => 8,
            'success' => 5,
            'warning' => 7,
        };
    }

    public function toPayload(): array
    {
        $payload = [
            'title' => $this->title,
            'message' => $this->message,
            'priority' => $this->getPriority(),
        ];

        // See: https://gotify.net/docs/msgextras
        if (! empty($this->buttons)) {
            $actions = [];

            foreach ($this->buttons as $button) {
                $buttonUrl = data_get($button, 'url');
                $text = data_get($button, 'text', 'Click here');

                // Replace localhost with actual app URL
                if ($buttonUrl && str_contains($buttonUrl, 'http://localhost')) {
                    $buttonUrl = str_replace('http://localhost', config('app.url'), $buttonUrl);
                }

                if ($buttonUrl) {
                    $actions[] = [
                        'label' => $text,
                        'url' => $buttonUrl,
                    ];
                }
            }

            if (! empty($actions)) {
                $payload['extras'] = [
                    'client::notification' => [
                        'click' => [
                            'url' => $actions[0]['url'], // Default click action
                        ],
                    ],
                    'client::display' => [
                        'contentType' => 'text/plain',
                    ],
                ];
            }
        }

        Log::info('Gotify message', $payload);

        return $payload;
    }
}
