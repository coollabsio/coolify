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
            'extras' => [
                'client::display' => [
                    'contentType' => 'text/markdown',
                ],
            ],
        ];

        // See about Gotify extras: https://gotify.net/docs/msgextras
        if (count($this->buttons) == 1) {
            $button = $this->buttons[0];
            $buttonUrl = data_get($button, 'url');
            if ($buttonUrl && str_contains($buttonUrl, 'http://localhost')) {
                $buttonUrl = str_replace('http://localhost', config('app.url'), $buttonUrl);
            }
            $payload['extras']['client::notification'] = [
                'click' => [
                    'url' => $buttonUrl,
                ],
            ];
        }

        foreach ($this->buttons as $button) {
            $buttonUrl = data_get($button, 'url');
            $text = data_get($button, 'text', 'Click here');

            // Replace localhost with actual app URL
            if ($buttonUrl && str_contains($buttonUrl, 'http://localhost')) {
                $buttonUrl = str_replace('http://localhost', config('app.url'), $buttonUrl);
            }

            $payload['message'] .= "\n\n[{$text}]({$buttonUrl})";
        }

        Log::info('Gotify message', $payload);

        return $payload;
    }
}
