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
        'is_literal' => ['type' => 'boolean'],
        'is_multiline' => ['type' => 'boolean'],
        'is_preview' => ['type' => 'boolean'],
        'is_runtime' => ['type' => 'boolean'],
        'is_buildtime' => ['type' => 'boolean'],
        'is_shared' => ['type' => 'boolean'],
        'is_shown_once' => ['type' => 'boolean'],
        'key' => ['type' => 'string'],
        'value' => ['type' => 'string'],
        'real_value' => ['type' => 'string'],
        'comment' => ['type' => 'string', 'nullable' => true],
        'version' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ]
)]
class EnvironmentVariable extends BaseModel
{
    protected $fillable = [
        // Core identification
        'key',
        'value',
        'comment',

        // Polymorphic relationship
        'resourceable_type',
        'resourceable_id',

        // Boolean flags
        'is_preview',
        'is_multiline',
        'is_literal',
        'is_runtime',
        'is_buildtime',
        'is_shown_once',
        'is_shared',
        'is_required',

        // Metadata
        'version',
        'order',
    ];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
        'is_multiline' => 'boolean',
        'is_preview' => 'boolean',
        'is_runtime' => 'boolean',
        'is_buildtime' => 'boolean',
        'version' => 'string',
        'resourceable_type' => 'string',
        'resourceable_id' => 'integer',
    ];

    protected $appends = ['real_value', 'is_shared', 'is_really_required', 'is_nixpacks', 'is_coolify'];

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
                    if ($application) {
                        ModelsEnvironmentVariable::create([
                            'key' => $environment_variable->key,
                            'value' => $environment_variable->value,
                            'is_multiline' => $environment_variable->is_multiline ?? false,
                            'is_literal' => $environment_variable->is_literal ?? false,
                            'is_runtime' => $environment_variable->is_runtime ?? false,
                            'is_buildtime' => $environment_variable->is_buildtime ?? false,
                            'comment' => $environment_variable->comment,
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

                $real_value = $this->get_real_environment_variables($this->value, $resource);

                // Skip escaping for valid JSON objects/arrays to prevent quote corruption (see #6160)
                if (json_validate($real_value) && (str_starts_with($real_value, '{') || str_starts_with($real_value, '['))) {
                    return $real_value;
                }

                if ($this->is_literal || $this->is_multiline) {
                    $real_value = '\''.$real_value.'\'';
                } else {
                    $real_value = escapeEnvVariables($real_value);
                }

                return $real_value;
            }
        );
    }

    protected function isReallyRequired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->is_required && str($this->real_value)->isEmpty(),
        );
    }

    protected function isNixpacks(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (str($this->key)->startsWith('NIXPACKS_')) {
                    return true;
                }

                return false;
            }
        );
    }

    protected function isCoolify(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (str($this->key)->startsWith('SERVICE_')) {
                    return true;
                }

                return false;
            }
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
        return resolveSharedEnvironmentVariables($environment_variable, $resource);
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
            return encrypt($environment_variable);
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
