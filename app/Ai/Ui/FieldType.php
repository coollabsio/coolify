<?php

namespace App\Ai\Ui;

enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Toggle = 'toggle';
    case Select = 'select';
    case Note = 'note';
    case Locked = 'locked';

    public function isEditable(): bool
    {
        return ! in_array($this, [self::Note, self::Locked], true);
    }
}
