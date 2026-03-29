<?php

namespace App\Notifications\Dto;

class NtfyMessage
{
    public ?int $customPriority = null;

    public function __construct(
        public string $title,
        public string $message,
        public array $buttons = [],
        public string $level = 'info',
    ) {}

    public function getLevelIcon(): string
    {
        return match ($this->level) {
            'info' => 'information_source',
            'error' => 'x',
            'success' => 'white_check_mark',
            'warning' => 'warning',
        };
    }

    public function getLevelPriority(): int
    {
        return match ($this->level) {
            'info' => 3,
            'success' => 2,
            'warning' => 4,
            'error' => 5,
        };
    }

    public function getEffectivePriority(): int
    {
        return $this->customPriority ?? $this->getLevelPriority();
    }

    public function toPayload(string $topic): array
    {
        $payload = [
            'topic' => $topic,
            'title' => $this->title,
            'message' => $this->message,
            'priority' => $this->getEffectivePriority(),
            'tags' => [$this->getLevelIcon()],
        ];

        $actions = [];
        foreach ($this->buttons as $button) {
            $buttonUrl = data_get($button, 'url');
            $text = data_get($button, 'text', 'Click here');
            if ($buttonUrl && str_contains($buttonUrl, 'http://localhost')) {
                $buttonUrl = str_replace('http://localhost', config('app.url'), $buttonUrl);
            }
            if ($buttonUrl) {
                $actions[] = [
                    'action' => 'view',
                    'label' => $text,
                    'url' => $buttonUrl,
                ];
            }
        }

        if (! empty($actions)) {
            $payload['actions'] = $actions;
        }

        return $payload;
    }
}
