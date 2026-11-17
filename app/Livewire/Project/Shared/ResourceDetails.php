<?php

namespace App\Livewire\Project\Shared;

use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ResourceDetails extends Component
{
    use AuthorizesRequests;

    public $resource;

    public ?string $project_uuid = null;

    public ?string $project_name = null;

    public ?string $environment_uuid = null;

    public ?string $environment_name = null;

    public ?string $server_uuid = null;

    public ?string $server_name = null;

    public array $stack_applications = [];

    public array $stack_databases = [];

    public function mount()
    {
        $this->authorize('view', $this->resource);

        $environment = $this->resource->environment ?? null;
        if ($environment) {
            $this->environment_uuid = $environment->uuid;
            $this->environment_name = $environment->name;
            $project = $environment->project ?? null;
            if ($project) {
                $this->project_uuid = $project->uuid;
                $this->project_name = $project->name;
            }
        }

        $server = $this->resolveServer();
        if ($server) {
            $this->server_uuid = $server->uuid;
            $this->server_name = $server->name;
        }

        if ($this->resource instanceof Service) {
            $this->stack_applications = $this->resource->applications
                ->map(fn ($app) => [
                    'name' => $app->human_name ?: $app->name,
                    'uuid' => $app->uuid,
                ])
                ->values()
                ->all();

            $this->stack_databases = $this->resource->databases
                ->map(fn ($db) => [
                    'name' => $db->human_name ?: $db->name,
                    'uuid' => $db->uuid,
                ])
                ->values()
                ->all();
        }
    }

    private function resolveServer()
    {
        try {
            if (isset($this->resource->destination) && $this->resource->destination && isset($this->resource->destination->server)) {
                return $this->resource->destination->server;
            }
            if (method_exists($this->resource, 'server') && $this->resource->server) {
                return $this->resource->server;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    public function render()
    {
        return view('livewire.project.shared.resource-details');
    }
}
