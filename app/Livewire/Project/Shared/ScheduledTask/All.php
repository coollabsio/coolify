<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Models\ScheduledTask;
use Livewire\Component;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Str;

class All extends Component
{
    public $resource;
    public bool $showPreview = false;
    public string|null $modalId = null;
    public ?string $variables = null;
    public ?string $variablesPreview = null;
    protected $listeners = ['refreshTasks', 'saveScheduledTask' => 'submit'];

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
        $this->variables = $this->resource->scheduled_tasks->map(function ($item) {
            error_log("** got one");
            return "$item->name=$item->command";
        })->sort();

        error_log(print_r($this->variables,1));
    }
    public function saveVariables($isPreview)
    {
        if ($isPreview) {
            $variables = parseEnvFormatToArray($this->variablesPreview);
            $this->resource->environment_variables_preview()->whereNotIn('key', array_keys($variables))->delete();
        } else {
            $variables = parseEnvFormatToArray($this->variables);
            $this->resource->environment_variables()->whereNotIn('key', array_keys($variables))->delete();
        }
        foreach ($variables as $key => $variable) {
            if ($isPreview) {
                $found = $this->resource->environment_variables_preview()->where('key', $key)->first();
            } else {
                $found = $this->resource->environment_variables()->where('key', $key)->first();
            }
            if ($found) {
                if ($found->is_shown_once) {
                    continue;
                }
                $found->value = $variable;
                $found->save();
                continue;
            } else {
                $task = new ScheduledTask();
                $task->key = $key;
                $task->value = $variable;
                $task->is_build_time = false;
                $task->is_preview = $isPreview ? true : false;
                switch ($this->resource->type()) {
                    case 'application':
                        $task->application_id = $this->resource->id;
                        break;
                    case 'standalone-postgresql':
                        $task->standalone_postgresql_id = $this->resource->id;
                        break;
                    case 'standalone-redis':
                        $task->standalone_redis_id = $this->resource->id;
                        break;
                    case 'standalone-mongodb':
                        $task->standalone_mongodb_id = $this->resource->id;
                        break;
                    case 'standalone-mysql':
                        $task->standalone_mysql_id = $this->resource->id;
                        break;
                    case 'standalone-mariadb':
                        $task->standalone_mariadb_id = $this->resource->id;
                        break;
                    case 'service':
                        $task->service_id = $this->resource->id;
                        break;
                }
                $task->save();
            }
        }
        if ($isPreview) {
            $this->dispatch('success', 'Preview environment variables updated successfully.');
        } else {
            $this->dispatch('success', 'Environment variables updated successfully.');
        }
        $this->refreshTasks();
    }
    public function refreshTasks()
    {
        $this->resource->refresh();
        $this->getDevView();
    }

    public function submit($data)
    {
        error_log("** submitting the beast");
        try {
            $task = new ScheduledTask();
            $task->name = $data['name'];
            $task->command = $data['command'];
            $task->frequency = $data['frequency'];
            $task->container = $data['container'];

            switch ($this->resource->type()) {
                case 'application':
                    $task->application_id = $this->resource->id;
                    break;
                case 'standalone-postgresql':
                    $task->standalone_postgresql_id = $this->resource->id;
                    break;
                case 'service':
                    $task->service_id = $this->resource->id;
                    break;
            }
            $task->save();
            $this->refreshTasks();
            $this->dispatch('success', 'Scheduled task added successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
