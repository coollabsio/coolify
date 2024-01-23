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
        'key' => 'string',
        'value' => 'encrypted',
        'is_build_time' => 'boolean',
    ];
    protected $appends = ['real_value', 'is_shared'];

    protected static function booted()
    {
        static::created(function ($environment_variable) {
            if ($environment_variable->application_id && !$environment_variable->is_preview) {
                $found = ModelsEnvironmentVariable::where('key', $environment_variable->key)->where('application_id', $environment_variable->application_id)->where('is_preview', true)->first();
                $application = Application::find($environment_variable->application_id);
                if ($application->build_pack === 'dockerfile') {
                    return;
                }
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
    public function service()
    {
        return $this->belongsTo(Service::class);
    }
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value = null) => $this->get_environment_variables($value),
            set: fn (?string $value = null) => $this->set_environment_variables($value),
        );
    }
    protected function realValue(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->get_real_environment_variables($this->value),
        );
    }
    protected function isShared(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = str($this->value)->after("{{")->before(".")->value;
                if (str($this->value)->startsWith('{{' . $type) && str($this->value)->endsWith('}}')) {
                    return true;
                }
                return false;
            }
        );
    }
    private function team()
    {
        if ($this->application_id) {
            $application = Application::find($this->application_id);
            if ($application) {
                return $application->team();
            }
        }
        if ($this->service_id) {
            $service = Service::find($this->service_id);
            if ($service) {
                return $service->team();
            }
        }
        if ($this->standalone_postgresql_id) {
            $standalone_postgresql = StandalonePostgresql::find($this->standalone_postgresql_id);
            if ($standalone_postgresql) {
                return $standalone_postgresql->team();
            }
        }
        if ($this->standalone_mysql_id) {
            $standalone_mysql = StandaloneMysql::find($this->standalone_mysql_id);
            if ($standalone_mysql) {
                return $standalone_mysql->team();
            }
        }
        if ($this->standalone_redis_id) {
            $standalone_redis = StandaloneRedis::find($this->standalone_redis_id);
            if ($standalone_redis) {
                return $standalone_redis->team();
            }
        }
        if ($this->standalone_mongodb_id) {
            $standalone_mongodb = StandaloneMongodb::find($this->standalone_mongodb_id);
            if ($standalone_mongodb) {
                return $standalone_mongodb->team();
            }
        }
        if ($this->standalone_mariadb_id) {
            $standalone_mariadb = StandaloneMariadb::find($this->standalone_mariadb_id);
            if ($standalone_mariadb) {
                return $standalone_mariadb->team();
            }
        }
    }
    private function get_real_environment_variables(?string $environment_variable = null): string|null
    {
        if (!$environment_variable) {
            return null;
        }
        $environment_variable = trim($environment_variable);
        $type = str($environment_variable)->after("{{")->before(".")->value;
        if (str($environment_variable)->startsWith("{{" . $type) && str($environment_variable)->endsWith('}}')) {
            $variable = Str::after($environment_variable, "{$type}.");
            $variable = Str::before($variable, '}}');
            $variable = Str::of($variable)->trim()->value;
            $environment_variable_found = SharedEnvironmentVariable::where("type", $type)->where('key', $variable)->where('team_id', $this->team()->id)->first();
            if ($environment_variable_found) {
                return $environment_variable_found->value;
            }
        }
        return $environment_variable;
    }
    private function get_environment_variables(?string $environment_variable = null): string|null
    {
        if (!$environment_variable) {
            return null;
        }
        return trim(decrypt($environment_variable));
    }

    private function set_environment_variables(?string $environment_variable = null): string|null
    {
        if (is_null($environment_variable) && $environment_variable == '') {
            return null;
        }
        $environment_variable = trim($environment_variable);
        $type = str($environment_variable)->after("{{")->before(".")->value;
        if (str($environment_variable)->startsWith("{{" . $type) && str($environment_variable)->endsWith('}}')) {
            return encrypt((string) str($environment_variable)->replace(' ', ''));
        }
        return encrypt($environment_variable);
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::of($value)->trim(),
        );
    }
}
