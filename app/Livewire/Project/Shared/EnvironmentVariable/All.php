<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable;
use Livewire\Component;

class All extends Component
{
    public $resource;

    public string $resourceClass;

    public bool $showPreview = false;

    public ?string $variables = null;

    public ?string $variablesPreview = null;

    public string $view = 'normal';

    public bool $is_env_sorting_enabled = false;

    protected $listeners = [
        'saveKey' => 'submit',
        'refreshEnvs',
        'environmentVariableDeleted' => 'refreshEnvs',
    ];

    public function mount()
    {
        $this->is_env_sorting_enabled = data_get($this->resource, 'settings.is_env_sorting_enabled', false);
        $this->resourceClass = get_class($this->resource);
        $resourceWithPreviews = [\App\Models\Application::class];
        $simpleDockerfile = filled(data_get($this->resource, 'dockerfile'));
        if (str($this->resourceClass)->contains($resourceWithPreviews) && ! $simpleDockerfile) {
            $this->showPreview = true;
        }
        $this->sortEnvironmentVariables();
    }

    public function instantSave()
    {
        $this->resource->settings->is_env_sorting_enabled = $this->is_env_sorting_enabled;
        $this->resource->settings->save();
        $this->sortEnvironmentVariables();
        $this->dispatch('success', 'Environment variable settings updated.');
    }

    public function sortEnvironmentVariables()
    {
        if ($this->is_env_sorting_enabled === false) {
            if ($this->resource->environment_variables) {
                $this->resource->environment_variables = $this->resource->environment_variables->sortBy('order')->values();
            }

            if ($this->resource->environment_variables_preview) {
                $this->resource->environment_variables_preview = $this->resource->environment_variables_preview->sortBy('order')->values();
            }
        }

        $this->getDevView();
    }

    public function getDevView()
    {
        $this->variables = $this->formatEnvironmentVariables($this->resource->environment_variables);
        if ($this->showPreview) {
            $this->variablesPreview = $this->formatEnvironmentVariables($this->resource->environment_variables_preview);
        }
    }

    private function formatEnvironmentVariables($variables)
    {
        return $variables->map(function ($item) {
            if ($item->is_shown_once) {
                return "$item->key=(Locked Secret, delete and add again to change)";
            }
            if ($item->is_multiline) {
                return "$item->key=(Multiline environment variable, edit in normal view)";
            }

            return "$item->key=$item->value";
        })->join("\n");
    }

    public function switch()
    {
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->sortEnvironmentVariables();
    }

    public function submit($data = null)
    {
        try {
            if ($data === null) {
                $this->handleBulkSubmit();
            } else {
                $this->handleSingleSubmit($data);
            }

            $this->updateOrder();
            $this->sortEnvironmentVariables();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->refreshEnvs();
        }
    }

    private function updateOrder()
    {
        $variables = parseEnvFormatToArray($this->variables);
        $order = 1;
        foreach ($variables as $key => $value) {
            $env = $this->resource->environment_variables()->where('key', $key)->first();
            if ($env) {
                $env->order = $order;
                $env->save();
            }
            $order++;
        }

        if ($this->showPreview) {
            $previewVariables = parseEnvFormatToArray($this->variablesPreview);
            $order = 1;
            foreach ($previewVariables as $key => $value) {
                $env = $this->resource->environment_variables_preview()->where('key', $key)->first();
                if ($env) {
                    $env->order = $order;
                    $env->save();
                }
                $order++;
            }
        }
    }

    private function handleBulkSubmit()
    {
        $variables = parseEnvFormatToArray($this->variables);

        $this->deleteRemovedVariables(false, $variables);
        $this->updateOrCreateVariables(false, $variables);

        if ($this->showPreview) {
            $previewVariables = parseEnvFormatToArray($this->variablesPreview);
            $this->deleteRemovedVariables(true, $previewVariables);
            $this->updateOrCreateVariables(true, $previewVariables);
        }

        $this->dispatch('success', 'Environment variables updated.');
    }

    private function handleSingleSubmit($data)
    {
        $found = $this->resource->environment_variables()->where('key', $data['key'])->first();
        if ($found) {
            $this->dispatch('error', 'Environment variable already exists.');

            return;
        }

        $maxOrder = $this->resource->environment_variables()->max('order') ?? 0;
        $environment = $this->createEnvironmentVariable($data);
        $environment->order = $maxOrder + 1;
        $environment->save();
    }

    private function createEnvironmentVariable($data)
    {
        $environment = new EnvironmentVariable;
        $environment->key = $data['key'];
        $environment->value = $data['value'];
        $environment->is_build_time = $data['is_build_time'] ?? false;
        $environment->is_multiline = $data['is_multiline'] ?? false;
        $environment->is_literal = $data['is_literal'] ?? false;
        $environment->is_preview = $data['is_preview'] ?? false;
        $environment->resourceable_id = $this->resource->id;
        $environment->resourceable_type = $this->resource->getMorphClass();

        return $environment;
    }

    private function deleteRemovedVariables($isPreview, $variables)
    {
        $method = $isPreview ? 'environment_variables_preview' : 'environment_variables';
        $this->resource->$method()->whereNotIn('key', array_keys($variables))->delete();
    }

    private function updateOrCreateVariables($isPreview, $variables)
    {
        foreach ($variables as $key => $value) {
            if (str($key)->startsWith('SERVICE_FQDN') || str($key)->startsWith('SERVICE_URL')) {
                continue;
            }
            $method = $isPreview ? 'environment_variables_preview' : 'environment_variables';
            $found = $this->resource->$method()->where('key', $key)->first();

            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline) {
                    $found->value = $value;
                    $found->save();
                }
            } else {
                $environment = new EnvironmentVariable;
                $environment->key = $key;
                $environment->value = $value;
                $environment->is_build_time = false;
                $environment->is_multiline = false;
                $environment->is_preview = $isPreview;
                $environment->resourceable_id = $this->resource->id;
                $environment->resourceable_type = $this->resource->getMorphClass();

                $environment->save();
            }
        }
    }

    public function refreshEnvs()
    {
        $this->resource->refresh();
        $this->sortEnvironmentVariables();
        $this->getDevView();
    }
}
