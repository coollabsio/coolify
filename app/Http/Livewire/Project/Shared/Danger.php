<?php

namespace App\Http\Livewire\Project\Shared;

use App\Actions\Service\StopService;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Danger extends Component
{
    public $resource;
    public array $parameters;
    public string|null $modalId = null;

    public function mount()
    {
        $this->modalId = new Cuid2(7);
        $this->parameters = get_route_parameters();
    }

    public function delete()
    {
        // Should be queued
        try {
            if ($this->resource->type() === 'service') {
                $server = $this->resource->server;
                StopService::run($this->resource);
            } else {
                $destination = data_get($this->resource, 'destination');
                if ($destination) {
                    $destination = $this->resource->destination->getMorphClass()::where('id', $this->resource->destination->id)->first();
                    $server = $destination->server;
                }
                instant_remote_process(["docker rm -f {$this->resource->uuid}"], $server);
            }
            $this->resource->delete();
            return redirect()->route('project.resources', [
                'project_uuid' => $this->parameters['project_uuid'],
                'environment_name' => $this->parameters['environment_name']
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
