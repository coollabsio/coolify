<?php

namespace App\Notifications\Dto;

class TeamsMessage
{
    private array $fields = [];

    public function __construct(
        public string $title,
        public string $description,
        public string $color = "#0078D7",
        public bool $isCritical = false,
    ) {}

    public static function successColor(): string
    {
        return "#a1ffa5";
    }

    public static function warningColor(): string
    {
        return "#ffa743";
    }

    public static function errorColor(): string
    {
        return "#ff705f";
    }

    public static function infoColor(): string
    {
        return "#4f545c";
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

        $facts = [];
        foreach ($this->fields as $field) {
            $facts[] = [
                'type' => 'FactSet',
                'facts' => [
                    [
                        'title' => $field['name'],
                        'value' => $field['value']
                    ]
                ]
            ];
        }

        // Adicionar timestamp
        $facts[] = [
            'type' => 'FactSet',
            'facts' => [
                [
                    'title' => 'Time',
                    'value' => now()->format('Y-m-d H:i:s')
                ]
            ]
        ];

        $payload = [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.2',
                        'body' => [
                            [
                                'type' => 'TextBlock',
                                'text' => $this->title,
                                'size' => 'Large',
                                'weight' => 'Bolder',
                                'color' => $this->color
                            ],
                            [
                                'type' => 'TextBlock',
                                'text' => $this->description,
                                'wrap' => true
                            ],
                            ...$facts,
                            [
                                'type' => 'TextBlock',
                                'text' => $footerText,
                                'size' => 'Small',
                                'isSubtle' => true
                            ]
                        ]
                    ]
                ]
            ]
        ];

        if ($this->isCritical) {
            $payload['content']['body'][] = [
                'type' => 'TextBlock',
                'text' => '⚠️ ATENÇÃO CRÍTICA ⚠️',
                'color' => 'attention',
                'weight' => 'Bolder',
                'size' => 'Medium'
            ];
        }

        return $payload;
    }
}
