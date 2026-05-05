
<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;
use Illuminate\Support\Carbon;

class ContainerInfo extends Component
{
    public Application $application;

    public array $parameters;

    public function mount()
    {
        $this->parameters = [
            'project_uuid' => $this->application->project()->uuid,
            'environment_uuid' => $this->application->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ];
    }

    public function getContainerInfoProperty()
    {
        $server = $this->application->destination->server;
        $containerId = $this->application->uuid;

        $containerData = getContainerStatus($server, $containerId, true);

        if ($containerData === 'exited' || empty($containerData)) {
            return null;
        }

        $container = $containerData;

        $createdAt = Carbon::parse(data_get($container, 'Created'));
        $status = data_get($container, 'State.Status');
        $image = data_get($container, 'Config.Image');
        $imageId = data_get($container, 'Image');

        $startedAt = null;
        $uptime = null;

        if ($status === 'running') {
            $startedAt = Carbon::parse(data_get($container, 'State.StartedAt'));
            $uptime = $startedAt->diffForHumans(null, true);
        }

        return [
            'id' => data_get($container, 'Id'),
            'image' => $image,
            'image_id' => $imageId,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'created_at_human' => $createdAt->diffForHumans(),
            'status' => $status,
            'started_at' => $startedAt ? $startedAt->format('Y-m-d H:i:s') : null,
            'uptime' => $uptime,
        ];
    }

    public function render()
    {
        return view('livewire.project.application.container-info');
    }
}
