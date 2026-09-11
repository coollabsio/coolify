<?php

namespace App\Notifications\Dto;

class GotifyMessage
{
    public function __construct(
        public string $title,
        public string $message,
        public array $buttons = [],
        public string $level = 'info',
    ) {}

    public function getLevelIcon(): string
    {
        return match ($this->level) {
            'info' => 'ℹ️',
            'error' => '❌',
            'success' => '✅ ',
            'warning' => '⚠️',
        };
    }

    public function getPriority(): int
    {
        return match ($this->level) {
            'warning' => 6,
            'error' => 8,
            default => 4,
        };
    }

    public function toPayload(): array
    {
        $levelIcon = $this->getLevelIcon();
        $message = $this->message;

        foreach ($this->buttons as $button) {
            $buttonUrl = data_get($button, 'url');
            $text = data_get($button, 'text', 'Click here');
            if ($buttonUrl && str_contains($buttonUrl, 'http://localhost')) {
                $buttonUrl = str_replace('http://localhost', config('app.url'), $buttonUrl);
            }
            $message .= "\n\n[".$text.']('.$buttonUrl.')';
        }

        return [
            'title' => "{$levelIcon} {$this->title}",
            'message' => $message,
            'priority' => $this->getPriority(),
            'extras' => [
                'client::display' => [
                    'contentType' => 'text/markdown',
                ],
            ],
        ];
    }
}
