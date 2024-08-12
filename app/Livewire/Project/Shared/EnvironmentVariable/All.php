<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class All extends Component
{
    public $resource;

    public string $resourceClass;

    public bool $showPreview = false;

    public ?string $modalId = null;

    public ?string $variables = null;

    public ?string $variablesPreview = null;

    public string $view = 'normal';

    protected $listeners = [
        'refreshEnvs',
        'saveKey' => 'submit',
    ];

    protected $rules = [
        'resource.settings.is_env_sorting_enabled' => 'required|boolean',
    ];

    public function mount()
    {
        $this->resourceClass = get_class($this->resource);
        $resourceWithPreviews = ['App\Models\Application'];
        $simpleDockerfile = ! is_null(data_get($this->resource, 'dockerfile'));
        if (str($this->resourceClass)->contains($resourceWithPreviews) && ! $simpleDockerfile) {
            $this->showPreview = true;
        }
        $this->modalId = new Cuid2;
        $this->sortMe();
        $this->getDevView();
    }

    public function sortMe()
    {
        if ($this->resourceClass === 'App\Models\Application' && data_get($this->resource, 'build_pack') !== 'dockercompose') {
            if ($this->resource->settings->is_env_sorting_enabled) {
                $this->resource->environment_variables = $this->resource->environment_variables->sortBy('key');
                $this->resource->environment_variables_preview = $this->resource->environment_variables_preview->sortBy('key');
            } else {
                $this->resource->environment_variables = $this->resource->environment_variables->sortBy('id');
                $this->resource->environment_variables_preview = $this->resource->environment_variables_preview->sortBy('id');
            }
        }
        $this->getDevView();
    }

    public function instantSave()
    {
        if ($this->resourceClass === 'App\Models\Application' && data_get($this->resource, 'build_pack') !== 'dockercompose') {
            $this->resource->settings->save();
            $this->dispatch('success', 'Environment variable settings updated.');
            $this->sortMe();
        }
    }

    public function getDevView()
    {
        $this->variables = $this->resource->environment_variables->map(function ($item) {
            if ($item->is_shown_once) {
                return "$item->key=(locked secret)";
            }
            if ($item->is_multiline) {
                return "$item->key=(multiline, edit in normal view)";
            }

            return "$item->key=$item->value";
        })->join('
');
        if ($this->showPreview) {
            $this->variablesPreview = $this->resource->environment_variables_preview->map(function ($item) {
                if ($item->is_shown_once) {
                    return "$item->key=(locked secret)";
                }
                if ($item->is_multiline) {
                    return "$item->key=(multiline, edit in normal view)";
                }

                return "$item->key=$item->value";
            })->join('
');
        }
    }

    public function switch()
    {
        if ($this->view === 'normal') {
            $this->view = 'dev';
        } else {
            $this->view = 'normal';
        }
        $this->sortMe();
    }

    public function submit()
    {
        try {
            $isPreview = $this->showPreview;
            $variables = $isPreview ? parseEnvFormatToArray($this->variablesPreview) : parseEnvFormatToArray($this->variables);

            if ($isPreview) {
                $this->resource->environment_variables_preview()->whereNotIn('key', array_keys($variables))->delete();
            } else {
                $this->resource->environment_variables()->whereNotIn('key', array_keys($variables))->delete();
            }

            foreach ($variables as $key => $value) {
                $found = $isPreview
                    ? $this->resource->environment_variables_preview()->where('key', $key)->first()
                    : $this->resource->environment_variables()->where('key', $key)->first();

                if ($found) {
                    if (!$found->is_shown_once && !$found->is_multiline) {
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

                    $this->setEnvironmentResourceId($environment);
                    $environment->save();
                }
            }

            $this->refreshEnvs();
            $this->dispatch('success', 'Environment variables saved successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function setEnvironmentResourceId($environment)
    {
        switch ($this->resource->type()) {
            case 'application':
                $environment->application_id = $this->resource->id;
                break;
            case 'standalone-postgresql':
                $environment->standalone_postgresql_id = $this->resource->id;
                break;
            case 'standalone-redis':
                $environment->standalone_redis_id = $this->resource->id;
                break;
            case 'standalone-mongodb':
                $environment->standalone_mongodb_id = $this->resource->id;
                break;
            case 'standalone-mysql':
                $environment->standalone_mysql_id = $this->resource->id;
                break;
            case 'standalone-mariadb':
                $environment->standalone_mariadb_id = $this->resource->id;
                break;
            case 'standalone-keydb':
                $environment->standalone_keydb_id = $this->resource->id;
                break;
            case 'standalone-dragonfly':
                $environment->standalone_dragonfly_id = $this->resource->id;
                break;
            case 'standalone-clickhouse':
                $environment->standalone_clickhouse_id = $this->resource->id;
                break;
            case 'service':
                $environment->service_id = $this->resource->id;
                break;
        }
    }

    public function refreshEnvs()
    {
        $this->resource->refresh();
        $this->getDevView();
    }
}