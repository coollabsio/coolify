<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class ServerEnvironmentVariable extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
        'is_multiline' => 'boolean',
        'is_literal' => 'boolean',
        'is_shown_once' => 'boolean',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->trim()->replace(' ', '_')->value,
        );
    }

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value = null) => $value ? trim(decrypt($value)) : null,
            set: fn (?string $value = null) => is_null($value) || $value === '' ? null : encrypt(trim($value)),
        );
    }

    public function realValue(): Attribute
    {
        return Attribute::make(
            get: function () {
                $real_value = $this->value;

                if (is_null($real_value)) {
                    return null;
                }

                if ($this->is_literal || $this->is_multiline) {
                    return '\'' . $real_value . '\'';
                }

                return escapeEnvVariables($real_value);
            }
        );
    }
}
