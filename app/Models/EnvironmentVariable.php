<?php

namespace App\Models;

use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EnvironmentVariable extends Model
{
    protected $guarded = [];
    protected $casts = [
        "key" => 'string',
        'value' => 'encrypted',
        'is_build_time' => 'boolean',
    ];

    protected static function booted()
    {
        static::created(function ($environment_variable) {
            if ($environment_variable->application_id && !$environment_variable->is_preview) {
                $found = ModelsEnvironmentVariable::where('key', $environment_variable->key)->where('application_id', $environment_variable->application_id)->where('is_preview',true)->first();
                if (!$found) {
                    ModelsEnvironmentVariable::create([
                        'key' => $environment_variable->key,
                        'value' => $environment_variable->value,
                        'is_build_time' => $environment_variable->is_build_time,
                        'application_id' => $environment_variable->application_id,
                        'is_preview' => true,
                    ]);
                }
            }
        });
    }

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => $this->get_environment_variables($value),
            set: fn (string $value) => $this->set_environment_variables($value),
        );
    }

    private function get_environment_variables(string $environment_variable): string|null
    {
        // $team_id = currentTeam()->id;
        if (str_contains(trim($environment_variable), '{{') && str_contains(trim($environment_variable), '}}')) {
            $environment_variable = preg_replace('/\s+/', '', $environment_variable);
            $environment_variable = str_replace('{{', '', $environment_variable);
            $environment_variable = str_replace('}}', '', $environment_variable);
            if (str_starts_with($environment_variable, 'global.')) {
                $environment_variable = str_replace('global.', '', $environment_variable);
                // $environment_variable = GlobalEnvironmentVariable::where('name', $environment_variable)->where('team_id', $team_id)->first()?->value;
                return $environment_variable;
            }
        }
        return decrypt($environment_variable);
    }

    private function set_environment_variables(string $environment_variable): string|null
    {
        $environment_variable = trim($environment_variable);
        if (!str_contains(trim($environment_variable), '{{') && !str_contains(trim($environment_variable), '}}')) {
            return encrypt($environment_variable);
        }
        return $environment_variable;
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::of($value)->trim(),
        );
    }
}
