<?php

namespace App\Notifications\Dto;

class DiscordMessage
{
    private array $fields = [];

    public function __construct(
        public string $title,
        public string $description,
        public int $color,
        public bool $isCritical = false,
    ) {}

    public static function successColor(): int
    {
        return hexdec('a1ffa5');
    }

    public static function warningColor(): int
    {
        return hexdec('ffa743');
    }

    public static function errorColor(): int
    {
        return hexdec('ff705f');
    }

    public static function infoColor(): int
    {
        return hexdec('4f545c');
    }

    public function addField(string $name, string $value, bool $inline = false): self
    {
        $this->fields[] = [
            'name' => $name,
            'value' => $value,
            'inline' => $inline,
        ];

        return $this;
    }

    public function toPayload(): array
    {
        $footerText = 'Coolify v'.config('constants.coolify.version');
        if (isCloud()) {
            $footerText = 'Coolify Cloud';
        }
        $payload = [
            'embeds' => [
                [
                    'title' => $this->title,
                    'description' => $this->description,
                    'color' => $this->color,
                    'fields' => $this->addTimestampToFields($this->fields),
                    'footer' => [
                        'text' => $footerText,
                    ],
                ],
            ],
        ];
        if ($this->isCritical) {
            $payload['content'] = '@here';
        }

        return $payload;
    }

    private function addTimestampToFields(array $fields): array
    {
        $fields[] = [
            'name' => 'Time',
            'value' => '<t:'.now()->timestamp.':R>',
            'inline' => true,
        ];

        return $fields;
    }
}
