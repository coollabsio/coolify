<?php

namespace App\Ai\Ui;

readonly class ApprovalForm
{
    /**
     * @param  array<int, Field>  $fields
     */
    public function __construct(
        public string $title,
        public bool $destructive,
        public array $fields,
    ) {}

    /**
     * @return array<int, string>
     */
    public function editableKeys(): array
    {
        return array_values(array_map(
            fn (Field $f) => $f->key,
            array_filter($this->fields, fn (Field $f) => $f->editable()),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'destructive' => $this->destructive,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields),
        ];
    }
}
