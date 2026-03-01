<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class ServerEnvironmentVariable extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
        'is_shown_once' => 'boolean',
        'is_literal' => 'boolean',
        'is_multiline' => 'boolean',
        'is_buildtime' => 'boolean',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value = null) => $this->getEnvironmentVariable($value),
            set: fn (?string $value = null) => $this->setEnvironmentVariable($value),
        );
    }

    protected function realValue(): Attribute
    {
        return Attribute::make(
            get: function () {
                $real_value = $this->value;

                if (is_null($real_value)) {
                    return null;
                }

                if ($this->is_literal || $this->is_multiline) {
                    $real_value = '\'' . $real_value . '\'';
                } else {
                    $real_value = escapeEnvVariables($real_value);
                }

                return $real_value;
            }
        );
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->trim()->replace(' ', '_')->value,
        );
    }

    private function getEnvironmentVariable(?string $value = null): ?string
    {
        if (! $value) {
            return null;
        }

        return trim(decrypt($value));
    }

    private function setEnvironmentVariable(?string $value = null): ?string
    {
        if (is_null($value) && $value === '') {
            return null;
        }

        return encrypt(trim($value));
    }
}
