<?php

namespace App\Notifications\Dto;

class NtfyMessage
{
    /** ntfy rejects or converts longer messages, so stay below its default 4096 byte limit. */
    public const MAX_MESSAGE_BYTES = 4000;

    /** ntfy accepts at most three actions per message. */
    public const MAX_ACTIONS = 3;

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
            'message' => $this->limitedMessage(),
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
            $payload['actions'] = array_slice($actions, 0, self::MAX_ACTIONS);
        }

        return $payload;
    }

    private function limitedMessage(): string
    {
        if (strlen($this->message) <= self::MAX_MESSAGE_BYTES) {
            return $this->message;
        }

        $ellipsis = '…';

        return mb_strcut($this->message, 0, self::MAX_MESSAGE_BYTES - strlen($ellipsis), 'UTF-8').$ellipsis;
    }
}
