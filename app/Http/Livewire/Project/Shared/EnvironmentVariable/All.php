<?php

namespace App\Http\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable;
use Livewire\Component;
use Visus\Cuid2\Cuid2;
use Str;

class All extends Component
{
    public $resource;
    public bool $showPreview = false;
    public string|null $modalId = null;
    public ?string $variables = null;
    public ?string $variablesPreview = null;
    public string $view = 'normal';
    protected $listeners = ['refreshEnvs', 'submit'];

    public function mount()
    {
        $resourceClass = get_class($this->resource);
        $resourceWithPreviews = ['App\Models\Application'];
        $simpleDockerfile = !is_null(data_get($this->resource, 'dockerfile'));
        if (Str::of($resourceClass)->contains($resourceWithPreviews) && !$simpleDockerfile) {
            $this->showPreview = true;
        }
        $this->modalId = new Cuid2(7);
        $this->getDevView();
    }
    public function getDevView()
    {
        $this->variables = $this->resource->environment_variables->map(function ($item) {
            return "$item->key=$item->value";
        })->sort()->join('
');
        if ($this->showPreview) {
            $this->variablesPreview = $this->resource->environment_variables_preview->map(function ($item) {
                return "$item->key=$item->value";
            })->sort()->join('
');
        }
    }
    public function switch()
    {
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
    }
    public function saveVariables($isPreview)
    {
        if ($isPreview) {
            $variables = parseEnvFormatToArray($this->variablesPreview);
            $existingVariables = $this->resource->environment_variables_preview();
            $this->resource->environment_variables_preview()->delete();
        } else {
            $variables = parseEnvFormatToArray($this->variables);
            $existingVariables = $this->resource->environment_variables();
            $this->resource->environment_variables()->delete();
        }
        foreach ($variables as $key => $variable) {
            $found = $existingVariables->where('key', $key)->first();
            if ($found) {
                $found->value = $variable;
                $found->save();
                continue;
            } else {
                $environment = new EnvironmentVariable();
                $environment->key = $key;
                $environment->value = $variable;
                $environment->is_build_time = false;
                $environment->is_preview = $isPreview ? true : false;
                if ($this->resource->type() === 'application') {
                    $environment->application_id = $this->resource->id;
                }
                if ($this->resource->type() === 'standalone-postgresql') {
                    $environment->standalone_postgresql_id = $this->resource->id;
                }
                $environment->save();
            }
        }
        $this->refreshEnvs();
    }
    public function refreshEnvs()
    {
        $this->resource->refresh();
        $this->getDevView();
    }

    public function submit($data)
    {
        try {
            $found = $this->resource->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                $this->emit('error', 'Environment variable already exists.');
                return;
            }
            $environment = new EnvironmentVariable();
            $environment->key = $data['key'];
            $environment->value = $data['value'];
            $environment->is_build_time = $data['is_build_time'];
            $environment->is_preview = $data['is_preview'];

            if ($this->resource->type() === 'application') {
                $environment->application_id = $this->resource->id;
            }
            if ($this->resource->type() === 'standalone-postgresql') {
                $environment->standalone_postgresql_id = $this->resource->id;
            }
            $environment->save();
            $this->refreshEnvs();
            $this->emit('success', 'Environment variable added successfully.');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
