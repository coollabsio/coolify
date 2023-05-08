<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EnvironmentVariable extends Model
{
    protected $fillable = ['key', 'value', 'is_build_time', 'application_id'];
    protected $casts = [
        "key" => 'string',
        'value' => 'encrypted',
        'is_build_time' => 'boolean',
    ];
    private function get_environment_variables(string $environment_variable): string|null
    {
        // $team_id = session('currentTeam')->id;
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
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => $this->get_environment_variables($value),
            set: fn (string $value) => $this->set_environment_variables($value),
        );
    }
    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::of($value)->trim(),
        );
    }
}
