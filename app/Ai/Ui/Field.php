<?php

namespace App\Ai\Ui;

readonly class Field
{
    /**
     * @param  array<int, string>  $options
     */
    public function __construct(
        public FieldType $type,
        public string $key,
        public string $label,
        public mixed $value = null,
        public array $options = [],
        public bool $required = false,
        public ?string $help = null,
    ) {}

    public function editable(): bool
    {
        return $this->type->isEditable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'options' => $this->options,
            'required' => $this->required,
            'help' => $this->help,
        ];
    }
}
