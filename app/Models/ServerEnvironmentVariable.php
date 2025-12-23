<?php

namespace App\Models;

use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Server Environment Variable model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'server_id' => ['type' => 'integer'],
        'key' => ['type' => 'string'],
        'value' => ['type' => 'string'],
        'is_literal' => ['type' => 'boolean'],
        'is_multiline' => ['type' => 'boolean'],
        'is_buildtime' => ['type' => 'boolean'],
        'is_runtime' => ['type' => 'boolean'],
        'version' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ]
)]
class ServerEnvironmentVariable extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
        'is_multiline' => 'boolean',
        'is_literal' => 'boolean',
        'is_runtime' => 'boolean',
        'is_buildtime' => 'boolean',
        'version' => 'string',
        'server_id' => 'integer',
    ];

    protected $appends = ['real_value'];

    protected static function booted()
    {
        static::created(function (ServerEnvironmentVariable $environment_variable) {
            $environment_variable->update([
                'version' => config('constants.coolify.version'),
            ]);
        });
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value = null) => $this->get_environment_variables($value),
            set: fn (?string $value = null) => $this->set_environment_variables($value),
        );
    }

    public function realValue(): Attribute
    {
        return Attribute::make(
            get: function () {
                $real_value = $this->get_environment_variables($this->value);
                if ($this->is_literal || $this->is_multiline) {
                    $real_value = '\''.$real_value.'\'';
                } else {
                    $real_value = escapeEnvVariables($real_value);
                }

                return $real_value;
            }
        );
    }

    private function get_environment_variables(?string $environment_variable = null): ?string
    {
        if (! $environment_variable) {
            return null;
        }

        return trim(decrypt($environment_variable));
    }

    private function set_environment_variables(?string $environment_variable = null): ?string
    {
        if (is_null($environment_variable) && $environment_variable === '') {
            return null;
        }
        $environment_variable = trim($environment_variable);

        return encrypt($environment_variable);
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->trim()->replace(' ', '_')->value,
        );
    }
}
