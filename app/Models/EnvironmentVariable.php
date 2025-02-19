<?php

namespace App\Models;

use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Environment Variable model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'resourceable_type' => ['type' => 'string'],
        'resourceable_id' => ['type' => 'integer'],
        'is_build_time' => ['type' => 'boolean'],
        'is_literal' => ['type' => 'boolean'],
        'is_multiline' => ['type' => 'boolean'],
        'is_preview' => ['type' => 'boolean'],
        'is_shared' => ['type' => 'boolean'],
        'is_shown_once' => ['type' => 'boolean'],
        'key' => ['type' => 'string'],
        'value' => ['type' => 'string'],
        'real_value' => ['type' => 'string'],
        'version' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ]
)]
class EnvironmentVariable extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
        'is_build_time' => 'boolean',
        'is_multiline' => 'boolean',
        'is_preview' => 'boolean',
        'version' => 'string',
        'resourceable_type' => 'string',
        'resourceable_id' => 'integer',
    ];

    protected $appends = ['real_value', 'is_shared', 'is_really_required'];

    protected static function booted()
    {
        static::created(function (EnvironmentVariable $environment_variable) {
            if ($environment_variable->resourceable_type === Application::class && ! $environment_variable->is_preview) {
                $found = ModelsEnvironmentVariable::where('key', $environment_variable->key)
                    ->where('resourceable_type', Application::class)
                    ->where('resourceable_id', $environment_variable->resourceable_id)
                    ->where('is_preview', true)
                    ->first();

                if (! $found) {
                    $application = Application::find($environment_variable->resourceable_id);
                    if ($application && $application->build_pack !== 'dockerfile') {
                        ModelsEnvironmentVariable::create([
                            'key' => $environment_variable->key,
                            'value' => $environment_variable->value,
                            'is_build_time' => $environment_variable->is_build_time,
                            'is_multiline' => $environment_variable->is_multiline ?? false,
                            'resourceable_type' => Application::class,
                            'resourceable_id' => $environment_variable->resourceable_id,
                            'is_preview' => true,
                        ]);
                    }
                }
            }
            $environment_variable->update([
                'version' => config('constants.coolify.version'),
            ]);
        });

        static::saving(function (EnvironmentVariable $environmentVariable) {
            $environmentVariable->updateIsShared();
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

    /**
     * Get the parent resourceable model.
     */
    public function resourceable()
    {
        return $this->morphTo();
    }

    public function resource()
    {
        return $this->resourceable;
    }

    public function realValue(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->relationLoaded('resourceable')) {
                    $this->load('resourceable');
                }
                $resource = $this->resourceable;
                if (! $resource) {
                    return null;
                }

                return $this->get_real_environment_variables($this->value, $resource);
            }
        );
    }

    protected function isReallyRequired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->is_required && str($this->real_value)->isEmpty(),
        );
    }

    protected function isShared(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = str($this->value)->after('{{')->before('.')->value;
                if (str($this->value)->startsWith('{{'.$type) && str($this->value)->endsWith('}}')) {
                    return true;
                }

                return false;
            }
        );
    }

    private function get_real_environment_variables(?string $environment_variable = null, $resource = null)
    {
        if ((is_null($environment_variable) && $environment_variable === '') || is_null($resource)) {
            return null;
        }
        $environment_variable = trim($environment_variable);
        $sharedEnvsFound = str($environment_variable)->matchAll('/{{(.*?)}}/');
        if ($sharedEnvsFound->isEmpty()) {
            return $environment_variable;
        }
        foreach ($sharedEnvsFound as $sharedEnv) {
            $type = str($sharedEnv)->match('/(.*?)\./');
            if (! collect(SHARED_VARIABLE_TYPES)->contains($type)) {
                continue;
            }
            $variable = str($sharedEnv)->match('/\.(.*)/');
            if ($type->value() === 'environment') {
                $id = $resource->environment->id;
            } elseif ($type->value() === 'project') {
                $id = $resource->environment->project->id;
            } elseif ($type->value() === 'team') {
                $id = $resource->team()->id;
            }
            if (is_null($id)) {
                continue;
            }
            $environment_variable_found = SharedEnvironmentVariable::where('type', $type)->where('key', $variable)->where('team_id', $resource->team()->id)->where("{$type}_id", $id)->first();
            if ($environment_variable_found) {
                $environment_variable = str($environment_variable)->replace("{{{$sharedEnv}}}", $environment_variable_found->value);
            }
        }

        return str($environment_variable)->value();
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
        $type = str($environment_variable)->after('{{')->before('.')->value;
        if (str($environment_variable)->startsWith('{{'.$type) && str($environment_variable)->endsWith('}}')) {
            return encrypt((string) str($environment_variable)->replace(' ', ''));
        }

        return encrypt($environment_variable);
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->trim()->replace(' ', '_')->value,
        );
    }

    protected function updateIsShared(): void
    {
        $type = str($this->value)->after('{{')->before('.')->value;
        $isShared = str($this->value)->startsWith('{{'.$type) && str($this->value)->endsWith('}}');
        $this->is_shared = $isShared;
    }
}
