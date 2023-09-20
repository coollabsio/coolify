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
                $found = ModelsEnvironmentVariable::where('key', $environment_variable->key)->where('application_id', $environment_variable->application_id)->where('is_preview', true)->first();
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
    public function service() {
        return $this->belongsTo(Service::class);
    }
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value = null) => $this->get_environment_variables($value),
            set: fn (?string $value = null) => $this->set_environment_variables($value),
        );
    }

    private function get_environment_variables(string $environment_variable): string|null
    {
        // $team_id = currentTeam()->id;
        $environment_variable = trim(decrypt($environment_variable));
        if (Str::startsWith($environment_variable, '{{') && Str::endsWith($environment_variable, '}}') && Str::contains($environment_variable, 'global.')) {
            $variable = Str::after($environment_variable, 'global.');
            $variable = Str::before($variable, '}}');
            $variable = Str::of($variable)->trim()->value;
               // $environment_variable = GlobalEnvironmentVariable::where('name', $environment_variable)->where('team_id', $team_id)->first()?->value;
               ray('global env variable');
               return $environment_variable;
        }
        return $environment_variable;
    }

    private function set_environment_variables(?string $environment_variable = null): string|null
    {
        if (is_null($environment_variable) && $environment_variable == '') {
            return null;
        }
        $environment_variable = trim($environment_variable);
        return encrypt($environment_variable);
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::of($value)->trim(),
        );
    }

}
